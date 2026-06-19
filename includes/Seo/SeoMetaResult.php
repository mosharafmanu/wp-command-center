<?php
/**
 * STEP 111 — GA#2 Slice 2b: SEO meta provider result value object.
 *
 * Immutable outcome of an SEO meta provider call. `ok()` carries the suggested
 * meta_title + meta_description + provenance (provider/model); `error()` carries a
 * {code,message} pair. It never carries a secret — an API key must never reach
 * this object (the provider/client redact any error text before constructing it).
 *
 * Distinct from the alt-text ProviderResult (single text string): SEO needs two
 * fields, so it gets its own shape rather than overloading the alt-text object.
 */

namespace WPCommandCenter\Seo;

defined( 'ABSPATH' ) || exit;

final class SeoMetaResult {

	private bool $ok;
	private string $meta_title;
	private string $meta_description;
	private string $provider;
	private string $model;
	/** @var array{code:string,message:string} */
	private array $error;

	/** @param array{code:string,message:string} $error */
	private function __construct( bool $ok, string $meta_title, string $meta_description, string $provider, string $model, array $error ) {
		$this->ok               = $ok;
		$this->meta_title       = $meta_title;
		$this->meta_description = $meta_description;
		$this->provider         = $provider;
		$this->model            = $model;
		$this->error            = $error;
	}

	public static function ok( string $meta_title, string $meta_description, string $provider, string $model ): self {
		return new self( true, $meta_title, $meta_description, $provider, $model, [] );
	}

	public static function error( string $code, string $message, string $provider = '', string $model = '' ): self {
		return new self( false, '', '', $provider, $model, [ 'code' => $code, 'message' => $message ] );
	}

	public function is_ok(): bool { return $this->ok; }
	public function meta_title(): string { return $this->meta_title; }
	public function meta_description(): string { return $this->meta_description; }
	public function provider(): string { return $this->provider; }
	public function model(): string { return $this->model; }
	/** @return array{code:string,message:string} */
	public function get_error(): array { return $this->error; }
}
