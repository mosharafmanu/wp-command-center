#!/usr/bin/env bash
#
# PROGRAM-4 / P4.0 — RollbackDelta core unit tests.
#
# Drives the runtime-agnostic RollbackDelta core through an in-memory FAKE
# FieldAccessor — NO WordPress, NO wp-cli. Proves the field-scoped, drift-aware
# capture/restore behaviour (the F-1 guarantees) is correct and storage-independent:
# empty-prior delete, value-prior restore, empty-but-existing restore, disjoint
# sibling preservation, same-field drift skip/conflict, out-of-order no-resurrection,
# existence fidelity, and the complete/partial/conflict status machine.
#
# Runs on plain php with an ABSPATH shim so it loads ONLY FieldAccessor + RollbackDelta
# (the core touches no WP function), demonstrating true decoupling.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP="${WPCC_PHP:-/Applications/AMPPS/apps/php82/bin/php}"
command -v "$PHP" >/dev/null 2>&1 || PHP=php
export WPCC_PLUGIN_DIR="$PLUGIN_DIR"

"$PHP" -d error_reporting=E_ALL -d display_errors=1 -r '
$DIR = getenv("WPCC_PLUGIN_DIR");
define("ABSPATH", sys_get_temp_dir() . "/");   // shim: satisfy the files\x27 ABSPATH guard
require $DIR . "/includes/Rollback/FieldAccessor.php";
require $DIR . "/includes/Rollback/RollbackDelta.php";

use WPCommandCenter\Rollback\FieldAccessor;
use WPCommandCenter\Rollback\RollbackDelta;

/** In-memory accessor: fields map 1:1 to a backing key of the same name, except
 *  "robots" which fans out to two keys and compares as an order-insensitive set. */
final class FakeAccessor implements FieldAccessor {
    /** @var array<string,mixed> present keys → value */
    public array $store = [];
    public function backing_keys( string $field ): array {
        return "robots" === $field ? [ "robots_a", "robots_b" ] : [ $field ];
    }
    public function read_field( $entity_id, string $field ) {
        if ( "robots" === $field ) {
            $out = [];
            foreach ( [ "robots_a", "robots_b" ] as $k ) {
                if ( array_key_exists( $k, $this->store ) && "" !== $this->store[$k] ) { $out[] = $this->store[$k]; }
            }
            sort( $out );
            return $out;
        }
        return array_key_exists( $field, $this->store ) ? (string) $this->store[$field] : "";
    }
    public function key_exists( $entity_id, string $key ): bool { return array_key_exists( $key, $this->store ); }
    public function key_get( $entity_id, string $key ) { return $this->store[$key] ?? ""; }
    public function key_set( $entity_id, string $key, $value ): void { $this->store[$key] = $value; }
    public function key_delete( $entity_id, string $key ): void { unset( $this->store[$key] ); }
    public function equals( string $field, $current, $after ): bool {
        if ( "robots" === $field ) {
            $c = is_array($current)?$current:[]; $e = is_array($after)?$after:[];
            sort($c); sort($e); return $c === $e;
        }
        return (string) $current === (string) $after;
    }
}

$P = 0; $F = 0;
function ok($d,$c){ global $P,$F; if($c){$P++; echo "  PASS: $d\n";} else {$F++; echo "  FAIL: $d\n";} }

/* Helper: build a v2 fields map from a capture + the post-write after values. */
function fields_from( array $prior, array $after ): array {
    $fields = [];
    foreach ( $prior as $field => $spec ) { $fields[$field] = [ "after" => $after[$field] ?? "", "keys" => $spec["keys"] ]; }
    return $fields;
}

echo "RollbackDelta core (fake accessor, no WordPress)\n";

/* ---- S1: empty-prior fidelity → rollback DELETES ---- */
$a = new FakeAccessor();                          // title absent
$prior = RollbackDelta::capture($a, 1, ["title"]);
ok("S1 capture marks title absent", false === $prior["title"]["keys"]["title"]["existed"]);
$a->store["title"] = "NEW";                       // apply
$fields = fields_from($prior, ["title"=>"NEW"]);
$r = RollbackDelta::restore($a, 1, $fields);
ok("S1 status complete", "complete" === $r["status"]);
ok("S1 absent-prior title deleted on rollback", ! array_key_exists("title", $a->store));

/* ---- S2: value-prior fidelity → rollback RESTORES exact ---- */
$a = new FakeAccessor(); $a->store["title"] = "ORIG";
$prior = RollbackDelta::capture($a, 1, ["title"]);
$a->store["title"] = "NEW";
$r = RollbackDelta::restore($a, 1, fields_from($prior, ["title"=>"NEW"]));
ok("S2 status complete", "complete" === $r["status"]);
ok("S2 prior title restored exactly", "ORIG" === $a->store["title"]);

/* ---- S2b: empty-but-existing prior → rollback restores the EMPTY row (not delete) ---- */
$a = new FakeAccessor(); $a->store["title"] = "";   // present but empty
$prior = RollbackDelta::capture($a, 1, ["title"]);
ok("S2b capture marks title existed", true === $prior["title"]["keys"]["title"]["existed"]);
$a->store["title"] = "NEW";
RollbackDelta::restore($a, 1, fields_from($prior, ["title"=>"NEW"]));
ok("S2b empty-but-existing restored as empty row", array_key_exists("title",$a->store) && "" === $a->store["title"]);

/* ---- S3: disjoint layered → rollback A preserves sibling B ---- */
$a = new FakeAccessor(); $a->store["title"]="ORIG_T"; $a->store["description"]="ORIG_D";
$pa = RollbackDelta::capture($a, 1, ["title"]);          // change A: title
$a->store["title"]="A_T";
$fa = fields_from($pa, ["title"=>"A_T"]);
$pb = RollbackDelta::capture($a, 1, ["description"]);    // change B: description (sibling)
$a->store["description"]="B_D";
$rA = RollbackDelta::restore($a, 1, $fa);                // roll back A
ok("S3 rollback A complete", "complete" === $rA["status"]);
ok("S3 title restored to ORIG_T", "ORIG_T" === $a->store["title"]);
ok("S3 sibling description (B) survives", "B_D" === $a->store["description"]);

/* ---- S4: same-field drift → conflict, no clobber ---- */
$a = new FakeAccessor(); $a->store["title"]="ORIG_T";
$pa = RollbackDelta::capture($a, 1, ["title"]); $a->store["title"]="A_T";   // A
$fa = fields_from($pa, ["title"=>"A_T"]);
$a->store["title"]="B_T";                                                   // B touches same field
$rA = RollbackDelta::restore($a, 1, $fa);                                   // roll back A → drift
ok("S4 rollback A is conflict", "conflict" === $rA["status"]);
ok("S4 conflict reason drift", ($rA["conflicts"][0]["reason"] ?? "") === "drift");
ok("S4 newer B title NOT clobbered", "B_T" === $a->store["title"]);
ok("S4 title reported skipped", in_array("title", $rA["skipped"], true));

/* ---- S5: out-of-order → roll back B then A, no resurrection ---- */
$a = new FakeAccessor(); $a->store["title"]="ORIG_T";
$pa = RollbackDelta::capture($a, 1, ["title"]); $a->store["title"]="A_T"; $fa = fields_from($pa, ["title"=>"A_T"]);
$pb = RollbackDelta::capture($a, 1, ["title"]); $a->store["title"]="B_T"; $fb = fields_from($pb, ["title"=>"B_T"]);
$rB = RollbackDelta::restore($a, 1, $fb);                 // roll back newer B first
ok("S5 rollback B complete", "complete" === $rB["status"]);
ok("S5 title back to A_T after B rollback", "A_T" === $a->store["title"]);
$rA = RollbackDelta::restore($a, 1, $fa);                 // then older A
ok("S5 retry A now complete", "complete" === $rA["status"]);
ok("S5 title back to ORIG_T (no resurrection)", "ORIG_T" === $a->store["title"]);

/* ---- S6: partial (one drifted sibling) is NOT a clean success ---- */
$a = new FakeAccessor(); $a->store["title"]="ORIG_T"; $a->store["description"]="ORIG_D";
$p = RollbackDelta::capture($a, 1, ["title","description"]);
$a->store["title"]="A_T"; $a->store["description"]="A_D";
$f = fields_from($p, ["title"=>"A_T","description"=>"A_D"]);
$a->store["description"]="LATER_D";                       // description drifts
$r = RollbackDelta::restore($a, 1, $f);
ok("S6 status partial", "partial" === $r["status"]);
ok("S6 restored=title", $r["restored"] === ["title"]);
ok("S6 skipped=description", $r["skipped"] === ["description"]);
ok("S6 title restored", "ORIG_T" === $a->store["title"]);
ok("S6 drifted description preserved", "LATER_D" === $a->store["description"]);

/* ---- S7: robots (multi-key, set-compare) round-trip ---- */
$a = new FakeAccessor();                                  // robots absent
$p = RollbackDelta::capture($a, 1, ["robots"]);
$a->store["robots_a"]="noindex"; $a->store["robots_b"]="nofollow";   // apply set
$after = $a->read_field(1,"robots");                      // ["nofollow","noindex"] sorted
$r = RollbackDelta::restore($a, 1, fields_from($p, ["robots"=>$after]));
ok("S7 robots rollback complete", "complete" === $r["status"]);
ok("S7 robots restored to empty (absent keys)", ! array_key_exists("robots_a",$a->store) && ! array_key_exists("robots_b",$a->store));

echo "\nRollbackDelta core: PASS=$P FAIL=$F\n";
exit($F>0?1:0);
'
