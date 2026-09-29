<?php
/**
 * MFSD shared components — concern flags, keyword lists, student age, SteveGPT helpers.
 *
 * Defined in DREAM_JUNK_JOBS_TECH_SPEC_v1_2_0 §14 and copied UNCHANGED into
 * life-wheel and mfsd-dream-life. Every function is guarded with function_exists()
 * so whichever plugin loads first defines it. When the Concerns widget is built,
 * this file moves into a shared mfsd-concerns plugin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ────────────────────────────────────────────────────────────────────
   14.1  Table wp_mfsd_concern_flags
   ──────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'mfsd_concern_flags_install' ) ) {
    /** Safe to run repeatedly (dbDelta). Flags survive student resets. */
    function mfsd_concern_flags_install(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'mfsd_concern_flags';
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  source_task varchar(40) NOT NULL,
  item_text varchar(255) NOT NULL,
  reason_text longtext NULL,
  severity varchar(10) NOT NULL DEFAULT 'concern',
  trigger_type varchar(20) NOT NULL,
  matched varchar(255) NULL,
  parent_note text NULL,
  status varchar(20) DEFAULT 'open',
  reviewed_by bigint(20) unsigned NULL,
  reviewed_at datetime NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user (user_id),
  KEY idx_status (status),
  KEY idx_severity (severity),
  KEY idx_task (source_task)
) $charset;" );
    }
}

/* ────────────────────────────────────────────────────────────────────
   14.2  Keyword lists + keyword pre-check
   ──────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'mfsd_concern_default_keywords' ) ) {
    /** Defaults — ❓ Mark + Steve to sign off; editable per plugin in admin. */
    function mfsd_concern_default_keywords( string $list ): string {
        $lists = array(
            'urgent'  => array( 'kill myself', 'want to die', 'wish i was dead', 'self harm', 'self-harm', 'hurt myself',
                                'cutting', 'hits me', 'hit me', 'hurts me', 'not safe at home' ),
            'concern' => array( 'bullied', 'bullying', 'scared', 'afraid', 'unsafe', 'lonely', 'alone', 'no one likes',
                                'no friends', 'hate myself', 'hate my life', 'starving', 'no food', 'hungry', 'run away',
                                'running away', 'shouting at me', 'sad all the time' ),
        );
        return implode( "\n", $lists[ $list ] ?? array() );
    }
}

if ( ! function_exists( 'mfsd_concern_keyword_list' ) ) {
    /** Parse a one-per-line option into a clean lowercase list. */
    function mfsd_concern_keyword_list( string $raw ): array {
        $out = array();
        foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
            $line = strtolower( trim( $line ) );
            if ( $line !== '' ) $out[] = $line;
        }
        return array_values( array_unique( $out ) );
    }
}

if ( ! function_exists( 'mfsd_concern_keyword_check' ) ) {
    /**
     * Whole-word, case-insensitive keyword pre-check. Runs in PHP before any AI call.
     * @return array|null ['severity' => 'urgent'|'concern', 'matched' => 'kw1, kw2'] or null.
     */
    function mfsd_concern_keyword_check( string $text, array $urgent, array $concern ): ?array {
        $text = ' ' . strtolower( wp_strip_all_tags( $text ) ) . ' ';
        $hits = array( 'urgent' => array(), 'concern' => array() );
        foreach ( array( 'urgent' => $urgent, 'concern' => $concern ) as $sev => $words ) {
            foreach ( $words as $kw ) {
                if ( $kw === '' ) continue;
                if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $kw, '/' ) . '(?![\p{L}\p{N}])/iu', $text ) ) {
                    $hits[ $sev ][] = $kw;
                }
            }
        }
        if ( ! $hits['urgent'] && ! $hits['concern'] ) return null;
        return array(
            'severity' => $hits['urgent'] ? 'urgent' : 'concern',
            'matched'  => substr( implode( ', ', array_merge( $hits['urgent'], $hits['concern'] ) ), 0, 255 ),
        );
    }
}

if ( ! function_exists( 'mfsd_concern_safeguarding_rule' ) ) {
    /** The §14.2 rule every per-item prompt ends with (verbatim). */
    function mfsd_concern_safeguarding_rule(): string {
        return "SAFEGUARDING: if the item or reason suggests they are unhappy, unsafe, being hurt\n"
             . "or struggling, do NOT analyse or reframe it. Write 2 gentle sentences acknowledging it\n"
             . "and encouraging them to talk to a trusted adult at home or school. Then on a final\n"
             . "line output exactly [CONCERN:urgent] if they mention wanting to die, hurting themselves\n"
             . "or someone hurting them; [CONCERN:yes] for other unhappiness or worry.\n"
             . "Otherwise output on a final line: [CONCERN:no]";
    }
}

if ( ! function_exists( 'mfsd_concern_parse_marker' ) ) {
    /**
     * Reads and removes the [CONCERN:no|yes|urgent] marker.
     * @return string 'urgent' | 'concern' | '' (none / no marker)
     */
    function mfsd_concern_parse_marker( string &$text ): string {
        $sev = '';
        if ( preg_match_all( '/\[\s*CONCERN\s*:\s*(no|yes|urgent)\s*\]/i', $text, $m ) ) {
            foreach ( $m[1] as $v ) {
                $v = strtolower( $v );
                if ( $v === 'urgent' ) $sev = 'urgent';
                elseif ( $v === 'yes' && $sev !== 'urgent' ) $sev = 'concern';
            }
        }
        $text = trim( preg_replace( '/\[\s*CONCERN\s*:[^\]]*\]/i', '', $text ) );
        return $sev;
    }
}

if ( ! function_exists( 'mfsd_raise_concern_flag' ) ) {
    /**
     * Store a concern flag. Never returned to the child.
     * The same user + task + item + reason is stored once: a repeat (e.g. a retry or a
     * regeneration) upgrades severity / trigger on the existing row instead of adding one.
     *
     * On raise: fires 'mfsd_concern_flag_raised' so the owning plugin clears its cached
     * parent_ai_summary; logs the flag ID only (no text).
     *
     * @return int flag ID (0 on failure)
     */
    function mfsd_raise_concern_flag( $user_id, $source_task, $item, $reason, $severity, $trigger, $matched = '' ): int {
        global $wpdb;
        $table    = $wpdb->prefix . 'mfsd_concern_flags';
        $user_id  = (int) $user_id;
        $item     = substr( sanitize_text_field( (string) $item ), 0, 255 );
        $reason   = sanitize_textarea_field( (string) $reason );
        $severity = $severity === 'urgent' ? 'urgent' : 'concern';
        $trigger  = in_array( $trigger, array( 'keyword', 'ai', 'both' ), true ) ? $trigger : 'keyword';
        $matched  = substr( (string) $matched, 0, 255 );
        if ( $user_id <= 0 || $source_task === '' ) return 0;

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, severity, trigger_type, matched FROM $table
             WHERE user_id = %d AND source_task = %s AND item_text = %s AND reason_text = %s LIMIT 1",
            $user_id, $source_task, $item, $reason
        ), ARRAY_A );

        if ( $existing ) {
            $new_sev  = ( $existing['severity'] === 'urgent' || $severity === 'urgent' ) ? 'urgent' : 'concern';
            $new_trig = $existing['trigger_type'] === $trigger ? $trigger : 'both';
            $new_match = $existing['matched'] ?: $matched;
            if ( $new_sev === $existing['severity'] && $new_trig === $existing['trigger_type'] && $new_match === $existing['matched'] ) {
                return (int) $existing['id'];
            }
            $wpdb->update( $table,
                array( 'severity' => $new_sev, 'trigger_type' => $new_trig, 'matched' => $new_match ),
                array( 'id' => (int) $existing['id'] ) );
            $flag_id = (int) $existing['id'];
        } else {
            $ok = $wpdb->insert( $table, array(
                'user_id'      => $user_id,
                'source_task'  => $source_task,
                'item_text'    => $item,
                'reason_text'  => $reason,
                'severity'     => $severity,
                'trigger_type' => $trigger,
                'matched'      => $matched !== '' ? $matched : null,
                'status'       => 'open',
            ) );
            if ( ! $ok ) {
                error_log( 'MFSD concern flag: insert failed for source_task ' . $source_task );
                return 0;
            }
            $flag_id = (int) $wpdb->insert_id;
        }

        error_log( 'MFSD concern flag raised: #' . $flag_id );
        do_action( 'mfsd_concern_flag_raised', $flag_id, $user_id, $source_task );
        return $flag_id;
    }
}

if ( ! function_exists( 'mfsd_get_concern_flags' ) ) {
    /** Flags for one child + task. Parent/admin use only — never send to the child. */
    function mfsd_get_concern_flags( int $user_id, string $source_task, bool $open_only = false ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'mfsd_concern_flags';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return array();
        $sql = "SELECT * FROM $table WHERE user_id = %d AND source_task = %s" . ( $open_only ? " AND status = 'open'" : '' ) . ' ORDER BY id ASC';
        return $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $source_task ), ARRAY_A ) ?: array();
    }
}

if ( ! function_exists( 'mfsd_concern_save_parent_note' ) ) {
    /** Saves the parent-summary AI's PARENT_NOTE line to this child's flags for the task. */
    function mfsd_concern_save_parent_note( int $user_id, string $source_task, string $note ): void {
        global $wpdb;
        $note = sanitize_text_field( $note );
        if ( $note === '' || strtolower( $note ) === 'none' ) return;
        $wpdb->update( $wpdb->prefix . 'mfsd_concern_flags',
            array( 'parent_note' => $note ),
            array( 'user_id' => $user_id, 'source_task' => $source_task ) );
    }
}

/* ────────────────────────────────────────────────────────────────────
   Student age — pp_dob first, then dob (fixes the old date_of_birth bug)
   ──────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'mfsd_get_student_age' ) ) {
    function mfsd_get_student_age( int $user_id ): ?int {
        $dob = get_user_meta( $user_id, 'pp_dob', true );
        if ( empty( $dob ) ) $dob = get_user_meta( $user_id, 'dob', true );
        if ( empty( $dob ) || ! is_string( $dob ) ) return null;
        $dob  = trim( $dob );
        $date = null;
        foreach ( array( 'Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd-M-Y', 'd-M-y', 'Y/m/d' ) as $fmt ) {
            $d = DateTime::createFromFormat( '!' . $fmt, $dob );
            if ( $d && $d->format( $fmt ) === $dob ) { $date = $d; break; }
        }
        if ( ! $date ) {
            try { $date = new DateTime( $dob ); } catch ( Exception $e ) { return null; }
        }
        $age = (int) $date->diff( new DateTime( 'today' ) )->y;
        return ( $age > 3 && $age < 100 ) ? $age : null;
    }
}

if ( ! function_exists( 'mfsd_min_words_for_age' ) ) {
    /** Ages 12 / 13 / 14+ → 30 / 50 / 100 words; unknown age → 30. */
    function mfsd_min_words_for_age( ?int $age ): int {
        if ( $age === null ) return 30;
        if ( $age >= 14 ) return 100;
        if ( $age >= 13 ) return 50;
        return 30;
    }
}

/* ────────────────────────────────────────────────────────────────────
   14.4  SteveGPT helpers
   ──────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'mfsd_steve_chatbot' ) ) {
    /**
     * The SteveGPT chatbot mapped to $option, or null when SteveGPT is unavailable
     * (class missing, mapping empty, or chatbot record missing/inactive).
     */
    function mfsd_steve_chatbot( string $option ) {
        if ( ! class_exists( 'SteveGPT_Chatbot' ) ) return null;
        $id = (string) get_option( $option, '' );
        if ( $id === '' ) return null;
        try {
            return SteveGPT_Chatbot::get( $id );
        } catch ( Exception $e ) {
            error_log( 'MFSD SteveGPT mapping ' . $option . ' unusable: ' . $e->getMessage() );
            return null;
        }
    }
}

if ( ! function_exists( 'mfsd_steve_query' ) ) {
    /** One task query; returns '' on failure so the caller can use its fallback text. */
    function mfsd_steve_query( $chatbot, string $prompt, int $user_id, string $log_label = '' ): string {
        if ( ! $chatbot ) return '';
        try {
            return trim( (string) $chatbot->query( $prompt, $user_id ) );
        } catch ( Exception $e ) {
            error_log( 'MFSD SteveGPT call failed' . ( $log_label ? " ($log_label)" : '' ) . ': ' . $e->getMessage() );
            return '';
        }
    }
}

if ( ! function_exists( 'mfsd_steve_chat_context' ) ) {
    /** Strips ", [, ] and newlines so the string is safe in a [stevegpt_chatbot context=""] attribute. */
    function mfsd_steve_chat_context( string $text ): string {
        $text = str_replace( array( '"', '[', ']', "\r", "\n" ), array( '', '', '', '', ' ' ), $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }
}

if ( ! function_exists( 'mfsd_steve_missing_mappings' ) ) {
    /** Which of these mapping options are empty (for the admin notice). */
    function mfsd_steve_missing_mappings( array $options ): array {
        $missing = array();
        foreach ( $options as $opt ) {
            if ( (string) get_option( $opt, '' ) === '' ) $missing[] = $opt;
        }
        return $missing;
    }
}
