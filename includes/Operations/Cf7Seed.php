<?php
/**
 * Step 18 — Contact Form 7 Seeder Operation.
 *
 * Generates functional CF7 forms using predefined templates and native APIs.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class Cf7Seed {

	private const TEMPLATES = [
		'contact_basic' => [
			'form' => "<label> Your Name (required)\n    [text* your-name] </label>\n\n<label> Your Email (required)\n    [email* your-email] </label>\n\n<label> Subject\n    [text your-subject] </label>\n\n<label> Your Message\n    [textarea your-message] </label>\n\n[submit \"Send\"]",
			'mail' => [
				'subject' => '[_site_title] "[your-subject]"',
				'sender'  => '[your-name] <[_site_admin_email]>',
				'body'    => "From: [your-name] <[your-email]>\nSubject: [your-subject]\n\nMessage Body:\n[your-message]\n\n-- \nThis e-mail was sent from a contact form on [_site_title] ([_site_url])",
			],
		],
		'newsletter'    => [
			'form' => "<label> Your Email (required)\n    [email* your-email] </label>\n\n[submit \"Subscribe\"]",
			'mail' => [
				'subject' => '[_site_title] Newsletter Subscription',
				'sender'  => '<[_site_admin_email]>',
				'body'    => "New newsletter subscription: [your-email]\n\n-- \nThis e-mail was sent from a contact form on [_site_title] ([_site_url])",
			],
		],
		'quote_request' => [
			'form' => "<label> Your Name (required)\n    [text* your-name] </label>\n\n<label> Your Email (required)\n    [email* your-email] </label>\n\n<label> Service Type\n    [select service-type \"Web Design\" \"SEO\" \"Marketing\"] </label>\n\n<label> Estimated Budget\n    [number budget min:1000] </label>\n\n<label> Project Details\n    [textarea your-message] </label>\n\n[submit \"Request Quote\"]",
			'mail' => [
				'subject' => '[_site_title] Quote Request from [your-name]',
				'sender'  => '[your-name] <[_site_admin_email]>',
				'body'    => "From: [your-name] <[your-email]>\nService: [service-type]\nBudget: [budget]\n\nDetails:\n[your-message]\n\n-- \nThis e-mail was sent from a contact form on [_site_title] ([_site_url])",
			],
		],
	];

	/**
	 * Run the CF7 seeding operation.
	 *
	 * @param array{
	 *     title: string,
	 *     form_template: string
	 * } $params
	 * @param array $context Optional metadata.
	 *
	 * @return array|\WP_Error Result summary or error.
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return new \WP_Error( 'wpcc_cf7_inactive', __( 'Contact Form 7 is not active.', 'wp-command-center' ) );
		}

		// Ensure CF7 is fully loaded (sometimes needed in REST context).
		if ( function_exists( 'wpcf7' ) ) {
			wpcf7();
		}

		$title    = sanitize_text_field( $params['title'] ?? 'Contact Form' );
		$template = sanitize_key( $params['form_template'] ?? 'contact_basic' );

		if ( ! isset( self::TEMPLATES[ $template ] ) ) {
			return new \WP_Error(
				'wpcc_invalid_cf7_template',
				sprintf( __( 'Invalid CF7 template "%s". Supported: contact_basic, newsletter, quote_request.', 'wp-command-center' ), $template )
			);
		}

		$data = self::TEMPLATES[ $template ];

		// Use the native template generator.
		$contact_form = \WPCF7_ContactForm::get_template();

		if ( ! $contact_form ) {
			return new \WP_Error( 'wpcc_cf7_init_failed', __( 'Failed to initialize CF7 form instance.', 'wp-command-center' ) );
		}

		// Configure the form data.
		$contact_form->set_title( $title );
		$contact_form->set_properties( [
			'form'     => $data['form'],
			'mail'     => array_merge( (array) $contact_form->prop( 'mail' ), (array) $data['mail'] ),
			'messages' => $contact_form->prop( 'messages' ),
		] );

		// WPCF7_ContactForm::save() handles post creation and meta synchronization.
		$id = $contact_form->save();

		if ( ! $id ) {
			return new \WP_Error( 'wpcc_cf7_save_failed', __( 'Failed to save Contact Form 7.', 'wp-command-center' ) );
		}

		return [
			'id'            => (int) $id,
			'title'         => $title,
			'form_template' => $template,
		];
	}
}
