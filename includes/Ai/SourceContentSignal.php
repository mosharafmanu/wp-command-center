<?php
/**
 * How much source material a generation actually had to work with.
 *
 * A tester generated an SEO title for a nearly-empty page and got back a title that
 * simply restated the page's existing one. Nothing had gone wrong: the prompts tell the
 * provider to describe ONLY what the content says and to invent no facts, so with two
 * sentences of source material a near-restatement is the correct, honest answer. But the
 * screen presented it as an ordinary suggestion, so it read as the AI having nothing to
 * offer — and the obvious "fix" would have been to loosen the prompt and let the model
 * invent claims about a page it had barely seen.
 *
 * The right answer is to say what happened. This is the one deterministic measure both
 * the SEO and Content generators use, so a thin page is described the same way wherever
 * it appears, and the number behind the judgement is reported alongside it.
 *
 * It is a NOTICE, never a gate: thin content still generates. Plenty of legitimate pages
 * are short, and refusing them would be a worse product than explaining them.
 *
 * Pure and deterministic — no I/O, no options, no provider call, same answer every time.
 */

namespace WPCommandCenter\Ai;

defined( 'ABSPATH' ) || exit;

final class SourceContentSignal {

	/** No usable source text at all. */
	public const NONE = 'none';

	/** Some text, but too little for a suggestion to add much beyond what is there. */
	public const THIN = 'thin';

	/** Enough to work from. */
	public const SUFFICIENT = 'sufficient';

	/**
	 * Word count below which source content is reported as thin.
	 *
	 * Chosen to sit under any real article and above a stub page — a heading plus a
	 * sentence or two lands under it, a short but genuine post clears it. Deliberately a
	 * single flat number rather than a per-post-type table: a threshold nobody can
	 * predict is worse than one that is occasionally generous.
	 */
	public const THIN_BELOW_WORDS = 40;

	/** Words in the source text (plain text in, no markup expected). */
	public static function word_count( string $plain_text ): int {
		$text = trim( preg_replace( '/\s+/u', ' ', $plain_text ) ?? '' );
		if ( '' === $text ) {
			return 0;
		}
		return count( preg_split( '/\s+/u', $text ) ?: [] );
	}

	/** Classify a word count: none | thin | sufficient. The rule, stated once. */
	private static function level_for_words( int $words ): string {
		if ( 0 === $words ) {
			return self::NONE;
		}
		return $words < self::THIN_BELOW_WORDS ? self::THIN : self::SUFFICIENT;
	}

	/** Classify source text: none | thin | sufficient. */
	public static function of( string $plain_text ): string {
		return self::level_for_words( self::word_count( $plain_text ) );
	}

	/** Whether this level warrants telling the customer their source is light. */
	public static function is_thin( string $level ): bool {
		return self::NONE === $level || self::THIN === $level;
	}

	/**
	 * The signal as generators report it in their response envelope.
	 *
	 * @return array{level:string,words:int,thin:bool}
	 */
	public static function describe( string $plain_text ): array {
		$words = self::word_count( $plain_text );
		$level = self::level_for_words( $words );
		return [
			'level' => $level,
			'words' => $words,
			'thin'  => self::is_thin( $level ),
		];
	}
}
