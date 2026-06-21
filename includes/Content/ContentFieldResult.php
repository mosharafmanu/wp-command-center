<?php
/**
 * Content-field AI generation — provider result value object.
 *
 * Immutable outcome of a content-field provider call. `ok()` carries the suggested
 * text (a content field is a single string) + provenance (provider/model); `error()`
 * carries a {code,message} pair. It never carries a secret — an API key must never
 * reach this object (the provider/client redact any error text before constructing it).
 *
 * Distinct from SeoMetaResult (two fields: meta_title + meta_description): a core
 * content field is one string, so it gets its own single-text shape rather than
 * overloading the SEO object.
 */

namespace WPCommandCenter\Content;

defined( 'ABSPATH' ) || exit;

final class ContentFieldResult {

	private bool $ok;
	private string $text;
	private string $provider;
	private string $model;
	/** @var array{code:string,message:string} */
	private array $error;

	/** @param array{code:string,message:string} $error */
	private function __construct( bool $ok, string $text, string $provider, string $model, array $error ) {
		$this->ok       = $ok;
		$this->text     = $text;
		$this->provider = $provider;
		$this->model    = $model;
		$this->error    = $error;
	}

	public static function ok( string $text, string $provider, string $model ): self {
		return new self( true, $text, $provider, $model, [] );
	}

	public static function error( string $code, string $message, string $provider = '', string $model = '' ): self {
		return new self( false, '', $provider, $model, [ 'code' => $code, 'message' => $message ] );
	}

	public function is_ok(): bool { return $this->ok; }
	public function text(): string { return $this->text; }
	public function provider(): string { return $this->provider; }
	public function model(): string { return $this->model; }
	/** @return array{code:string,message:string} */
	public function get_error(): array { return $this->error; }
}
