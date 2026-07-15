<?php
/**
 * Keyword-based lead classifier for Smart lead routing.
 *
 * Scores form submission text against admin-managed keyword lists to decide
 * whether a generic contact message is vendor/spam, a sales lead, support, or
 * unsure. No network calls; safe to run synchronously on submission.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight keyword scoring classifier.
 */
class GF_Odoo_Lead_Classifier {

	public const SPAM    = 'spam';
	public const SALES   = 'sales';
	public const SUPPORT = 'support';
	public const UNSURE  = 'unsure';

	/**
	 * Classify a Gravity Forms entry.
	 *
	 * @param array $entry  GF entry.
	 * @param array $form   GF form.
	 * @param array $config Smart routing config (keyword lists, thresholds).
	 *
	 * @return array{bucket:string,confident:bool,scores:array,matched:array,text:string,domain:string}
	 */
	public static function classify( array $entry, array $form, array $config ): array {
		$signals = self::gather_signals( $entry, $form );

		return self::classify_text(
			$signals['text'],
			$signals['domain'],
			$signals['links'],
			$config
		) + array(
			'text'   => $signals['text'],
			'domain' => $signals['domain'],
			'email'  => $signals['email'],
		);
	}

	/**
	 * Score already-gathered text.
	 *
	 * @param string $text   Lowercased submission text.
	 * @param string $domain Sender email domain.
	 * @param int    $links  Number of links found in the text.
	 * @param array  $config Smart routing config.
	 *
	 * @return array{bucket:string,confident:bool,scores:array,matched:array}
	 */
	public static function classify_text( string $text, string $domain, int $links, array $config ): array {
		$text = self::normalize( $text );

		$spam_match    = self::count_matches( $text, (array) ( $config['spam_keywords'] ?? array() ) );
		$sales_match   = self::count_matches( $text, (array) ( $config['sales_keywords'] ?? array() ) );
		$support_match = self::count_matches( $text, (array) ( $config['support_keywords'] ?? array() ) );

		$max_links            = max( 0, (int) ( $config['max_links'] ?? 3 ) );
		$spam_threshold       = max( 1, (int) ( $config['spam_threshold'] ?? 2 ) );
		$confidence_threshold = max( 1, (int) ( $config['confidence_threshold'] ?? 2 ) );

		$domain_blocked = '' !== $domain && in_array( strtolower( $domain ), array_map( 'strtolower', (array) ( $config['blocked_domains'] ?? array() ) ), true );
		$links_excess   = $max_links > 0 && $links > $max_links;

		$spam_score = $spam_match['count'] + ( $domain_blocked ? 2 : 0 ) + ( $links_excess ? 1 : 0 );

		$scores = array(
			self::SPAM    => $spam_score,
			self::SALES   => $sales_match['count'],
			self::SUPPORT => $support_match['count'],
		);

		$matched = array(
			self::SPAM    => $spam_match['matched'],
			self::SALES   => $sales_match['matched'],
			self::SUPPORT => $support_match['matched'],
		);

		// Spam is evaluated first; a blocked domain alone is enough.
		if ( $domain_blocked || $spam_score >= $spam_threshold ) {
			return array(
				'bucket'    => self::SPAM,
				'confident' => true,
				'scores'    => $scores,
				'matched'   => $matched,
			);
		}

		$sales_hits   = $sales_match['count'];
		$support_hits = $support_match['count'];

		if ( 0 === $sales_hits && 0 === $support_hits ) {
			return array(
				'bucket'    => self::UNSURE,
				'confident' => false,
				'scores'    => $scores,
				'matched'   => $matched,
			);
		}

		// Tie breaks toward Sales (never lose a potential lead).
		$winner   = $sales_hits >= $support_hits ? self::SALES : self::SUPPORT;
		$win_hits = max( $sales_hits, $support_hits );

		if ( $win_hits >= $confidence_threshold ) {
			return array(
				'bucket'    => $winner,
				'confident' => true,
				'scores'    => $scores,
				'matched'   => $matched,
			);
		}

		return array(
			'bucket'    => self::UNSURE,
			'confident' => false,
			'scores'    => $scores,
			'matched'   => $matched,
			'lean'      => $winner,
		);
	}

	/**
	 * Gather scannable text, sender email and domain, and link count from an entry.
	 *
	 * @param array $entry GF entry.
	 * @param array $form  GF form.
	 *
	 * @return array{text:string,email:string,domain:string,links:int}
	 */
	public static function gather_signals( array $entry, array $form ): array {
		$parts = array();

		foreach ( $entry as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			// Only field inputs (numeric keys like "3" or "3.6"); skip GF meta columns.
			if ( ! preg_match( '/^\d+(\.\d+)?$/', (string) $key ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		$text  = implode( "\n", $parts );
		$email = self::find_email( $entry, $form, $text );
		$domain = '';

		if ( '' !== $email && false !== strpos( $email, '@' ) ) {
			$domain = substr( strrchr( $email, '@' ), 1 );
		}

		$links = preg_match_all( '#https?://|www\.#i', $text );

		return array(
			'text'   => $text,
			'email'  => $email,
			'domain' => strtolower( $domain ),
			'links'  => (int) $links,
		);
	}

	/**
	 * Best-effort sender email lookup (email field first, then a regex scan).
	 *
	 * @param array  $entry GF entry.
	 * @param array  $form  GF form.
	 * @param string $text  Gathered text.
	 *
	 * @return string
	 */
	private static function find_email( array $entry, array $form, string $text ): string {
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();

		foreach ( $fields as $field ) {
			$type = is_object( $field ) ? ( $field->type ?? '' ) : ( $field['type'] ?? '' );

			if ( 'email' !== $type ) {
				continue;
			}

			$id    = is_object( $field ) ? ( $field->id ?? '' ) : ( $field['id'] ?? '' );
			$value = isset( $entry[ (string) $id ] ) ? trim( (string) $entry[ (string) $id ] ) : '';

			if ( '' !== $value && is_email( $value ) ) {
				return sanitize_email( $value );
			}
		}

		if ( preg_match( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m ) ) {
			return sanitize_email( $m[0] );
		}

		return '';
	}

	/**
	 * Count keyword matches with rough word boundaries.
	 *
	 * @param string $text     Normalized text.
	 * @param array  $keywords Keyword list.
	 *
	 * @return array{count:int,matched:array<int,string>}
	 */
	private static function count_matches( string $text, array $keywords ): array {
		$matched = array();

		if ( '' === $text ) {
			return array(
				'count'   => 0,
				'matched' => array(),
			);
		}

		foreach ( $keywords as $keyword ) {
			$keyword = self::normalize( (string) $keyword );

			if ( '' === $keyword ) {
				continue;
			}

			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $keyword, '/' ) . '(?![\p{L}\p{N}])/u';
			$hit     = @preg_match( $pattern, $text );

			if ( false === $hit ) {
				// Fallback when the regex engine rejects the input (e.g. invalid UTF-8).
				$hit = ( false !== strpos( $text, $keyword ) ) ? 1 : 0;
			}

			if ( $hit ) {
				$matched[] = $keyword;
			}
		}

		return array(
			'count'   => count( $matched ),
			'matched' => $matched,
		);
	}

	/**
	 * Lowercase and collapse whitespace for matching.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private static function normalize( string $value ): string {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );

		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}

	/**
	 * Parse a textarea (one term per line) into a clean list.
	 *
	 * @param string $value Raw textarea value.
	 *
	 * @return array<int,string>
	 */
	public static function lines_to_list( string $value ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $value );
		$list  = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' !== $line ) {
				$list[] = $line;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Shipped default spam/vendor keywords (EN/NL/DE/FR).
	 *
	 * @return array<int,string>
	 */
	public static function default_spam_keywords(): array {
		return array(
			'seo', 'backlink', 'backlinks', 'guest post', 'link building', 'rank #1 on google',
			'increase your sales', 'boost your traffic', 'our agency', 'we offer', 'partnership opportunity',
			'lead generation', 'b2b leads', 'digital marketing', 'outsourcing', 'dropshipping',
			'cryptocurrency', 'limited time offer', 'web design services',
			'wij bieden', 'samenwerking', 'vrijblijvend', 'hoger in google', 'meer bezoekers',
			'wir bieten', 'suchmaschinenoptimierung', 'reichweite steigern', 'zusammenarbeit',
			'nous proposons', 'référencement', 'partenariat',
		);
	}

	/**
	 * Shipped default sales keywords (EN/NL/DE/FR).
	 *
	 * @return array<int,string>
	 */
	public static function default_sales_keywords(): array {
		return array(
			'quote', 'pricing', 'price', 'cost', 'purchase', 'buy', 'order', 'demo', 'interested in',
			'distributor', 'reseller', 'wholesale', 'become a partner', 'request a quote', 'availability',
			'lease', 'trial', 'inbody 970', 'inbody 770', 'inbody 580',
			'offerte', 'prijs', 'kopen', 'bestellen', 'aanschaf', 'interesse', 'dealer', 'distributeur',
			'angebot', 'preis', 'kaufen', 'bestellen', 'interesse', 'händler',
			'devis', 'prix', 'acheter', 'commande', 'intéressé', 'revendeur',
		);
	}

	/**
	 * Shipped default support keywords (EN/NL/DE/FR).
	 *
	 * @return array<int,string>
	 */
	public static function default_support_keywords(): array {
		return array(
			'support', 'help', 'problem', 'issue', 'broken', 'not working', 'error', 'repair', 'warranty',
			'rma', 'defect', 'manual', 'how do i', 'calibration', 'will not turn on', 'firmware',
			'replacement part', 'service', 'maintenance', 'troubleshoot',
			'storing', 'kapot', 'defect', 'werkt niet', 'garantie', 'reparatie', 'handleiding',
			'ondersteuning', 'probleem', 'foutmelding',
			'störung', 'defekt', 'funktioniert nicht', 'garantie', 'reparatur', 'anleitung', 'fehler', 'wartung',
			'panne', 'cassé', 'ne fonctionne pas', 'garantie', 'réparation', 'manuel', 'erreur',
		);
	}

	/**
	 * Shipped default free/disposable email domains.
	 *
	 * @return array<int,string>
	 */
	public static function default_blocked_domains(): array {
		return array(
			'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com',
			'trashmail.com', 'yopmail.com', 'sharklasers.com',
		);
	}
}
