<?php
/**
 * STEP 110 — Phase 2 (AI Alt Text), Task 7B: provider result value object.
 *
 * Immutable outcome of a provider call. `ok()` carries the suggested text +
 * provenance (provider/model/confidence); `error()` carries a {code,message}
 * pair. It never carries a secret — an API key must never reach this object (the
 * provider redacts any error text before constructing it).
 */

namespace WPCommandCenter\AltText;

defined( 'ABSPATH' ) || exit;

final class ProviderResult {

	private bool $ok;
	private string $text;
	private string $provider;
	private string $model;
	private ?float $confidence;
	/** @var array{code:string,message:string} */
	private array $error;

	/** @param array{code:string,message:string} $error */
	private function __construct( bool $ok, string $text, string $provider, string $model, ?float $confidence, array $error ) {
		$this->ok         = $ok;
		$this->text       = $text;
		$this->provider   = $provider;
		$this->model      = $model;
		$this->confidence = $confidence;
		$this->error      = $error;
	}

	public static function ok( string $text, string $provider, string $model, ?float $confidence = null ): self {
		return new self( true, $text, $provider, $model, $confidence, [] );
	}

	public static function error( string $code, string $message, string $provider = '', string $model = '' ): self {
		return new self( false, '', $provider, $model, null, [ 'code' => $code, 'message' => $message ] );
	}

	public function is_ok(): bool { return $this->ok; }
	public function text(): string { return $this->text; }
	public function provider(): string { return $this->provider; }
	public function model(): string { return $this->model; }
	public function confidence(): ?float { return $this->confidence; }
	/** @return array{code:string,message:string} */
	public function get_error(): array { return $this->error; }

	/** Plain-array projection (no secrets — provider already redacted any error). */
	public function to_array(): array {
		return [
			'ok'         => $this->ok,
			'text'       => $this->text,
			'provider'   => $this->provider,
			'model'      => $this->model,
			'confidence' => $this->confidence,
			'error'      => $this->ok ? null : $this->error,
		];
	}
}
