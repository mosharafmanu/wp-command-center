<?php
/**
 * Phase D — Universal AI Provider Runtime: OpenAI-compatible codec.
 *
 * Translates a neutral GenerationRequest into an OpenAI Chat Completions request
 * body and reads an OpenAI Chat Completions response back into plain text. Pure,
 * stateless wire translation — it performs no I/O and knows no endpoint, header,
 * or auth. The transport owns those; the codec owns only the body/response shape.
 *
 * Message mapping (neutral → OpenAI):
 *   - a text-only message → { role, content: "<text>" } (string content),
 *   - a message with an image → { role, content: [ {type:text,text}, … ,
 *       {type:image_url, image_url:{ url:"data:<mime>;base64,<data>" }} ] }.
 */

namespace WPCommandCenter\Ai\Transport;

use WPCommandCenter\Ai\Contract\GenerationImagePart;
use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationTextPart;

defined( 'ABSPATH' ) || exit;

final class OpenAiCompatibleCodec {

	/**
	 * Build the Chat Completions request body.
	 *
	 * @param array<string,mixed> $profile
	 * @return array<string,mixed>
	 */
	public static function request_body( GenerationRequest $request, array $profile ): array {
		$token_param = (string) ( $profile['token_param'] ?? 'max_tokens' );

		return [
			'model'      => $request->model(),
			'messages'   => self::messages( $request ),
			$token_param => $request->max_tokens(),
		];
	}

	/**
	 * Map neutral messages to OpenAI messages.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function messages( GenerationRequest $request ): array {
		$out = [];

		foreach ( $request->messages() as $message ) {
			$has_image = false;
			foreach ( $message->parts() as $part ) {
				if ( $part instanceof GenerationImagePart ) {
					$has_image = true;
					break;
				}
			}

			if ( $has_image ) {
				$content = [];
				foreach ( $message->parts() as $part ) {
					if ( $part instanceof GenerationTextPart ) {
						$content[] = [ 'type' => 'text', 'text' => $part->text() ];
					} elseif ( $part instanceof GenerationImagePart ) {
						$content[] = [
							'type'      => 'image_url',
							'image_url' => [ 'url' => 'data:' . $part->media_type() . ';base64,' . $part->base64_data() ],
						];
					}
				}
				$out[] = [ 'role' => $message->role(), 'content' => $content ];
				continue;
			}

			// Text-only message → string content (concatenated text parts).
			$text = '';
			foreach ( $message->parts() as $part ) {
				if ( $part instanceof GenerationTextPart ) {
					$text .= $part->text();
				}
			}
			$out[] = [ 'role' => $message->role(), 'content' => $text ];
		}

		return $out;
	}

	/** Extract the assistant text from a parsed Chat Completions response. */
	public static function parse_text( $data ): string {
		if ( is_array( $data ) && isset( $data['choices'][0]['message']['content'] ) ) {
			return trim( (string) $data['choices'][0]['message']['content'] );
		}
		return '';
	}

	/** Extract a provider error message from a parsed error response. */
	public static function error_message( $data ): string {
		if ( is_array( $data ) ) {
			return (string) ( $data['error']['message'] ?? '' );
		}
		return '';
	}
}
