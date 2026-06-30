<?php
/**
 * Optional AI classifier for Smart lead routing (Beta).
 *
 * Sends a short, minimised message to an EU-friendly provider (Mistral by
 * default, or a custom OpenAI-compatible endpoint) and asks it to classify the
 * message into spam_vendor / sales_lead / support. Returns null on any failure
 * so callers can fall back to keyword scoring. Runs only in the async worker,
 * never on the submission request.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin REST client + strict JSON classifier.
 */
class GF_Odoo_AI_Classifier {

	/**
	 * Whether AI classification is usable with the given config.
	 *
	 * @param array $config Smart routing config.
	 *
	 * @return bool
	 */
	public static function is_configured( array $config ): bool {
		return ! empty( $config['ai_enabled'] )
			&& '' !== trim( (string) ( $config['ai_key'] ?? '' ) )
			&& '' !== trim( (string) ( $config['ai_endpoint'] ?? '' ) );
	}

	/**
	 * Run a single live request and return detailed diagnostics. Unlike
	 * classify(), this surfaces the failure reason so the admin "Test AI"
	 * button can explain what went wrong.
	 *
	 * @param string $text   Sample message to classify.
	 * @param array  $config Smart routing config.
	 *
	 * @return array{ok:bool,message:string,category?:string,confidence?:float,reason?:string,http_code?:int}
	 */
	public static function diagnose( string $text, array $config ): array {
		if ( empty( $config['ai_enabled'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'AI engine is off. Set Engine to "Hybrid (keywords + AI)" to use AI.', 'gf-odoo-connector' ),
			);
		}

		if ( '' === trim( (string) ( $config['ai_key'] ?? '' ) ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'No AI API key is saved. Enter and save the API key first.', 'gf-odoo-connector' ),
			);
		}

		if ( '' === trim( (string) ( $config['ai_endpoint'] ?? '' ) ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'No AI endpoint resolved. For a custom provider, set the Base URL.', 'gf-odoo-connector' ),
			);
		}

		$response = self::request( $text, array(), $config );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Request failed: %s', 'gf-odoo-connector' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$detail = '';
			$json   = json_decode( $body, true );

			if ( is_array( $json ) ) {
				$detail = (string) ( $json['error']['message'] ?? $json['message'] ?? '' );
			}

			return array(
				'ok'        => false,
				'http_code' => $code,
				'message'   => sprintf(
					/* translators: 1: HTTP status code, 2: provider error detail */
					__( 'Provider returned HTTP %1$d. %2$s', 'gf-odoo-connector' ),
					$code,
					'' !== $detail ? $detail : __( 'Check the API key, model name, and base URL.', 'gf-odoo-connector' )
				),
			);
		}

		$decoded = json_decode( $body, true );
		$content = is_array( $decoded ) ? (string) ( $decoded['choices'][0]['message']['content'] ?? '' ) : '';
		$parsed  = '' !== $content ? self::parse_json_object( $content ) : null;
		$category = is_array( $parsed ) ? self::normalize_category( (string) ( $parsed['category'] ?? '' ) ) : null;

		if ( null === $category ) {
			return array(
				'ok'        => false,
				'http_code' => $code,
				'message'   => __( 'Connected, but the model reply could not be parsed. Try a different model.', 'gf-odoo-connector' ),
			);
		}

		$confidence = isset( $parsed['confidence'] ) ? max( 0.0, min( 1.0, (float) $parsed['confidence'] ) ) : 0.0;

		return array(
			'ok'         => true,
			'http_code'  => $code,
			'category'   => $category,
			'confidence' => $confidence,
			'reason'     => sanitize_text_field( (string) ( $parsed['reason'] ?? '' ) ),
			'message'    => sprintf(
				/* translators: 1: category, 2: confidence percentage, 3: reason */
				__( 'Working. Classified the sample as "%1$s" (%2$d%% confidence). %3$s', 'gf-odoo-connector' ),
				$category,
				(int) round( $confidence * 100 ),
				sanitize_text_field( (string) ( $parsed['reason'] ?? '' ) )
			),
		);
	}

	/**
	 * Perform the raw chat-completions request.
	 *
	 * @param string $text    Message text.
	 * @param array  $context Optional context: domain.
	 * @param array  $config  Smart routing config.
	 *
	 * @return array|\WP_Error wp_remote_post result.
	 */
	private static function request( string $text, array $context, array $config ) {
		$text = trim( $text );

		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, 4000 );
		} else {
			$text = substr( $text, 0, 4000 );
		}

		$model  = (string) ( $config['ai_model'] ?? '' );
		$model  = '' !== $model ? $model : 'mistral-small-latest';
		$domain = (string) ( $context['domain'] ?? '' );

		$system = 'You are a strict router for inbound contact-form messages sent to a B2B company that sells professional body composition analysers (InBody). '
			. 'Classify the message into exactly one category: '
			. '"spam_vendor" = someone trying to sell us their own services or products (SEO, marketing, link building, web design, partnership/cooperation solicitations, unrelated offers); '
			. '"support" = an existing customer needing help (technical issue, malfunction, repair, warranty, manual, calibration, complaint about a device they own); '
			. '"sales_lead" = a potential or existing customer interested in buying or learning about our products (pricing, quote, demo, availability, distributor/reseller). '
			. 'When unsure between sales and support, prefer sales_lead. '
			. 'Respond with ONLY compact JSON, no prose: {"category":"spam_vendor|sales_lead|support","confidence":0.0,"reason":"max 12 words"}.';

		$user = 'Sender domain: ' . ( '' !== $domain ? $domain : 'unknown' ) . "\n\nMessage:\n" . $text;

		$body = array(
			'model'           => $model,
			'temperature'     => 0,
			'max_tokens'      => 200,
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);

		return wp_remote_post(
			(string) $config['ai_endpoint'],
			array(
				'timeout' => max( 3, (int) ( $config['ai_timeout'] ?? 10 ) ),
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . trim( (string) $config['ai_key'] ),
				),
				'body'    => wp_json_encode( $body ),
			)
		);
	}

	/**
	 * Classify a message via the configured AI provider.
	 *
	 * @param string $text    Message text (already gathered/minimised).
	 * @param array  $context Optional context: domain.
	 * @param array  $config  Smart routing config.
	 *
	 * @return array{category:string,confidence:float,reason:string}|null
	 */
	public static function classify( string $text, array $context, array $config ): ?array {
		if ( ! self::is_configured( $config ) ) {
			return null;
		}

		$text = trim( $text );

		if ( '' === $text ) {
			return null;
		}

		$response = self::request( $text, $context, $config );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$content = '';

		if ( is_array( $decoded ) ) {
			$content = (string) ( $decoded['choices'][0]['message']['content'] ?? '' );
		}

		if ( '' === $content ) {
			return null;
		}

		$parsed = self::parse_json_object( $content );

		if ( ! is_array( $parsed ) ) {
			return null;
		}

		$category = self::normalize_category( (string) ( $parsed['category'] ?? '' ) );

		if ( null === $category ) {
			return null;
		}

		$confidence = isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0.0;
		$confidence = max( 0.0, min( 1.0, $confidence ) );

		return array(
			'category'   => $category,
			'confidence' => $confidence,
			'reason'     => sanitize_text_field( (string) ( $parsed['reason'] ?? '' ) ),
		);
	}

	/**
	 * Decode a JSON object, tolerating a code-fence or surrounding text.
	 *
	 * @param string $content Raw model output.
	 *
	 * @return array|null
	 */
	private static function parse_json_object( string $content ) {
		$decoded = json_decode( trim( $content ), true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/\{.*\}/s', $content, $m ) ) {
			$decoded = json_decode( $m[0], true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Map a returned category string to a known bucket key.
	 *
	 * @param string $category Raw category.
	 *
	 * @return string|null spam_vendor|sales_lead|support, or null.
	 */
	private static function normalize_category( string $category ): ?string {
		$category = strtolower( trim( $category ) );

		if ( '' === $category ) {
			return null;
		}

		if ( false !== strpos( $category, 'spam' ) || false !== strpos( $category, 'vendor' ) ) {
			return 'spam_vendor';
		}

		if ( false !== strpos( $category, 'support' ) || false !== strpos( $category, 'helpdesk' ) ) {
			return 'support';
		}

		if ( false !== strpos( $category, 'sales' ) || false !== strpos( $category, 'lead' ) ) {
			return 'sales_lead';
		}

		return null;
	}
}
