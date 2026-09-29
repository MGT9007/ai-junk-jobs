<?php
/**
 * Plugin Name: AI Dream & Junk Jobs
 * Description: Week 2 Task 2. Students enter, rank and explain 5 dream jobs and 5 junk jobs, then get a combined SteveGPT results screen with personality fit, requirement ticks, plot twists and a careers chat. Use shortcode [ai_junk_jobs] (alias [ai_dream_junk_jobs]).
 * Version: 5.0.0
 * Author: MisterT9007
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/mfsd-concern-flags.php';
require_once __DIR__ . '/includes/class-mfsd-parent-tabs.php';
require_once __DIR__ . '/includes/class-junk-jobs-ai.php';

class AI_Junk_Jobs {
    const VERSION      = '5.0.0';
    const TABLE        = 'mfsd_ai_junk_jobs_results';
    const NONCE_ACTION = 'wp_rest';
    const TASK_SLUG    = 'junk_jobs';

    const MAP_SUMMARY  = 'mfsd_stevegpt_map_junk_jobs_summary';
    const MAP_PARENT   = 'mfsd_stevegpt_map_junk_jobs_parent_summary';
    const MAP_CHAT     = 'mfsd_stevegpt_map_junk_jobs_chat';

    const OPT_URGENT   = 'ai_junk_jobs_urgent_keywords';
    const OPT_CONCERN  = 'ai_junk_jobs_concern_keywords';

    const UNAVAILABLE_MSG = "Steve's having a quick break. Your answers are saved, so try again in a few minutes.";

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        add_action( 'init', array( $this, 'register_assets' ) );
        add_shortcode( 'ai_junk_jobs', array( $this, 'shortcode' ) );
        add_shortcode( 'ai_dream_junk_jobs', array( $this, 'shortcode' ) );
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'plugins_loaded', array( $this, 'check_version' ) );
        add_filter( 'stevegpt_plugin_integration_slots', array( $this, 'register_stevegpt_slots' ) );
        add_action( 'mfsd_concern_flag_raised', array( $this, 'on_flag_raised' ), 10, 3 );
    }

    public function register_stevegpt_slots( array $slots ): array {
        $slots[] = array( 'plugin' => 'Dream & Junk Jobs', 'role' => 'Summary (dream + junk + conclusion)', 'option' => self::MAP_SUMMARY, 'tokens' => array() );
        $slots[] = array( 'plugin' => 'Dream & Junk Jobs', 'role' => 'Parent summary',                      'option' => self::MAP_PARENT,  'tokens' => array() );
        $slots[] = array( 'plugin' => 'Dream & Junk Jobs', 'role' => 'Careers chatbot',                     'option' => self::MAP_CHAT,    'tokens' => array() );
        return $slots;
    }

    /** A new flag means the cached parent summary must lead with it next time. */
    public function on_flag_raised( $flag_id, $user_id, $source_task ) {
        if ( $source_task !== self::TASK_SLUG ) return;
        global $wpdb;
        MFSD_Parent_Tabs::clear_parent_summary( $wpdb->prefix . self::TABLE, (int) $user_id );
    }

    public function check_version() {
        $saved_version = get_option( 'ai_junk_jobs_version' );
        if ( $saved_version !== self::VERSION ) {
            $this->maybe_upgrade_table();
            mfsd_concern_flags_install();
            flush_rewrite_rules();
            update_option( 'ai_junk_jobs_version', self::VERSION );
            error_log( 'AI Dream & Junk Jobs: Upgraded to version ' . self::VERSION );
        }
    }

    /* ── Table upgrade — adds new columns to existing installs (SHOW COLUMNS guard) ── */
    private function maybe_upgrade_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->on_activate();
            return;
        }

        $columns = $wpdb->get_col( "SHOW COLUMNS FROM $table", 0 );

        if ( ! in_array( 'intro_text', $columns ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN intro_text LONGTEXT NULL AFTER analysis" );
        }
        for ( $i = 1; $i <= 5; $i++ ) {
            if ( ! in_array( "job_summary_$i", $columns ) ) {
                $wpdb->query( "ALTER TABLE $table ADD COLUMN job_summary_$i LONGTEXT NULL AFTER intro_text" );
            }
        }
        if ( ! in_array( 'conclusion', $columns ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN conclusion LONGTEXT NULL AFTER job_summary_5" );
        }

        // v5.0.0 — dream half + combined results
        $new = array( 'dream_items_json', 'dream_ranking_json', 'dream_reasons_json',
                      'dream_summary_1', 'dream_summary_2', 'dream_summary_3', 'dream_summary_4', 'dream_summary_5',
                      'fit_json', 'requirements_json', 'check_json', 'match_json', 'also_like_json', 'parent_ai_summary' );
        foreach ( $new as $col ) {
            if ( ! in_array( $col, $columns ) ) {
                $wpdb->query( "ALTER TABLE $table ADD COLUMN $col LONGTEXT NULL" );
            }
        }
    }

    public function on_activate() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id            BIGINT UNSIGNED NOT NULL,
            jobs_json          LONGTEXT NULL,
            ranking_json       LONGTEXT NULL,
            reasons_json       LONGTEXT NULL,
            analysis           LONGTEXT NULL,
            intro_text         LONGTEXT NULL,
            job_summary_1      LONGTEXT NULL,
            job_summary_2      LONGTEXT NULL,
            job_summary_3      LONGTEXT NULL,
            job_summary_4      LONGTEXT NULL,
            job_summary_5      LONGTEXT NULL,
            conclusion         LONGTEXT NULL,
            dream_items_json   LONGTEXT NULL,
            dream_ranking_json LONGTEXT NULL,
            dream_reasons_json LONGTEXT NULL,
            dream_summary_1    LONGTEXT NULL,
            dream_summary_2    LONGTEXT NULL,
            dream_summary_3    LONGTEXT NULL,
            dream_summary_4    LONGTEXT NULL,
            dream_summary_5    LONGTEXT NULL,
            fit_json           LONGTEXT NULL,
            requirements_json  LONGTEXT NULL,
            check_json         LONGTEXT NULL,
            match_json         LONGTEXT NULL,
            also_like_json     LONGTEXT NULL,
            parent_ai_summary  LONGTEXT NULL,
            mbti_type          CHAR(4) NULL,
            status             VARCHAR(20) DEFAULT 'not_started',
            created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user (user_id),
            KEY idx_status (status),
            UNIQUE KEY idx_user_unique (user_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        mfsd_concern_flags_install();
        flush_rewrite_rules();
    }

    public function register_assets() {
        $handle = 'ai-junk-jobs';
        wp_register_script(
            $handle,
            plugins_url( 'assets/ai-junk-jobs.js', __FILE__ ),
            array(),
            self::VERSION,
            true
        );
        wp_register_style(
            $handle,
            plugins_url( 'assets/ai-junk-jobs.css', __FILE__ ),
            array(),
            self::VERSION
        );
    }

    /* ================================================================
       SHORTCODE
       ================================================================ */
    public function shortcode( $atts, $content = null ) {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;padding:40px;color:#555;">Please log in to see Dream &amp; Junk Jobs.</p>';
        }
        $handle    = 'ai-junk-jobs';
        $viewer_id = $this->get_current_user_id();

        // ── Parent view (Course Progress) — skips the ordering gate, never writes status ──
        if ( MFSD_Parent_Tabs::is_parent_view() ) {
            $student_id = MFSD_Parent_Tabs::requested_student_id();
            if ( ! $student_id ) {
                return '<p style="text-align:center;padding:40px;color:#555;">Open this task from your child\'s Course Progress page to see their results.</p>';
            }
            $linked = MFSD_Parent_Tabs::assert_linked( $viewer_id, $student_id );
            if ( is_wp_error( $linked ) ) {
                return '<p style="text-align:center;padding:40px;color:#555;">You don\'t have access to this student\'s results.</p>';
            }

            wp_enqueue_script( $handle );
            wp_enqueue_style( $handle );
            $child_name = MFSD_Parent_Tabs::child_first_name( $student_id );
            $this->add_config( $handle, array(
                'parentView'    => true,
                'studentId'     => $student_id,
                'childName'     => $child_name,
                'restUrlParent' => esc_url_raw( rest_url( 'ai-junk-jobs/v1/parent-summary' ) ),
            ) );

            $open_flags = mfsd_get_concern_flags( $student_id, self::TASK_SLUG, true );
            return MFSD_Parent_Tabs::render_tabs(
                '<div id="ai-junk-jobs-root"></div>',
                $this->parent_panel_html( $student_id, $viewer_id, false ),
                ! empty( $open_flags ),
                $child_name
            );
        }

        // ── Ordering gate (students) ────────────────────────────────────
        if ( function_exists( 'mfsd_get_task_status' ) && get_option( 'ai_junk_jobs_course_management', 1 ) ) {
            $status = mfsd_get_task_status( $viewer_id, self::TASK_SLUG );
            if ( $status === 'locked' ) {
                if ( function_exists( 'mfsd_ordering_locked_message' ) ) {
                    return mfsd_ordering_locked_message( self::TASK_SLUG );
                }
                return '<p style="text-align:center;padding:40px;color:#555;">This activity is not available yet. Please complete the previous activity first.</p>';
            }
            if ( $status === 'available' ) {
                mfsd_set_task_status( $viewer_id, self::TASK_SLUG, 'in_progress' );
            }
        }

        wp_enqueue_script( $handle );
        wp_enqueue_style( $handle );

        // ── Careers chat (SteveGPT widget; hidden until the task is completed) ──
        $chat_html = '';
        $chat_id   = (string) get_option( self::MAP_CHAT, '' );
        if ( $chat_id && shortcode_exists( 'stevegpt_chatbot' ) ) {
            $row     = $this->get_row( $viewer_id );
            $context = ( $row && $row['status'] === 'completed' && ! $this->is_legacy( $row ) ) ? $this->chat_context( $row, $viewer_id ) : '';
            $chat_html = do_shortcode( '[stevegpt_chatbot id="' . esc_attr( $chat_id ) . '"'
                . ( $context !== '' ? ' context="' . esc_attr( $context ) . '"' : '' ) . ']' );
        }

        $this->add_config( $handle, array( 'hasChat' => $chat_html !== '' ) );

        return '<div id="ai-junk-jobs-root"></div>'
             . ( $chat_html !== '' ? '<div id="ai-junk-jobs-chat-source" style="display:none">' . $chat_html . '</div>' : '' );
    }

    private function add_config( string $handle, array $extra ) {
        $user_id = $this->get_current_user_id();
        $target  = ! empty( $extra['studentId'] ) ? (int) $extra['studentId'] : $user_id;
        $config  = array_merge( array(
            'restUrlSubmit' => esc_url_raw( rest_url( 'ai-junk-jobs/v1/submit' ) ),
            'restUrlStatus' => esc_url_raw( rest_url( 'ai-junk-jobs/v1/status' ) ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'userId'        => $user_id,
            'minWords'      => mfsd_min_words_for_age( mfsd_get_student_age( $target ) ),
            'parentView'    => false,
            'studentId'     => 0,
            'isAdmin'       => current_user_can( 'manage_options' ),
            'hasChat'       => false,
            'unavailableMsg'=> self::UNAVAILABLE_MSG,
            'urlBadges'     => 'https://mfsd.me/badges/',
            'urlCourse'     => 'https://mfsd.me/about/parent-portal-home/?course_id=1&student_id=' . $target,
        ), $extra );

        wp_add_inline_script(
            $handle,
            'window.AI_JUNK_JOBS_CFG = ' . wp_json_encode( $config ) . ';',
            'before'
        );
    }

    public function register_routes() {
        register_rest_route( 'ai-junk-jobs/v1', '/submit', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_submit' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ) );
        register_rest_route( 'ai-junk-jobs/v1', '/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_status' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ) );
        register_rest_route( 'ai-junk-jobs/v1', '/parent-summary', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_parent_summary' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ) );
    }

    public function check_permission( WP_REST_Request $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'unauthorized', 'You must be logged in', array( 'status' => 401 ) );
        }
        return true;
    }

    /* ================================================================
       DATA HELPERS
       ================================================================ */
    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    private function get_row( int $user_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d LIMIT 1", $user_id ), ARRAY_A );
        return $row ?: null;
    }

    /** Upsert only the given columns — never wipes the other half (unlike $wpdb->replace). */
    private function save_fields( int $user_id, array $fields ): bool {
        global $wpdb;
        if ( $this->get_row( $user_id ) ) {
            return $wpdb->update( $this->table(), $fields, array( 'user_id' => $user_id ) ) !== false;
        }
        return (bool) $wpdb->insert( $this->table(), array_merge( array( 'user_id' => $user_id ), $fields ) );
    }

    private static function dec( $json ): array {
        $v = is_string( $json ) && $json !== '' ? json_decode( $json, true ) : null;
        return is_array( $v ) ? $v : array();
    }

    /** Legacy junk-only row: junk data but no dream half yet (Decision 3). */
    private function is_legacy( ?array $row ): bool {
        return $row && empty( $row['dream_items_json'] ) && in_array( $row['status'], array( 'in_progress', 'reasons', 'completed' ), true );
    }

    /** All 5 ranked junk jobs have a reason. */
    private function junk_ready( array $row ): bool {
        $ranking = self::dec( $row['ranking_json'] ?? '' );
        $reasons = self::dec( $row['reasons_json'] ?? '' );
        if ( count( $ranking ) !== 5 ) return false;
        foreach ( $ranking as $job ) {
            if ( trim( (string) ( $reasons[ $job ] ?? '' ) ) === '' ) return false;
        }
        return true;
    }

    /** 5 unique, sanitised, non-empty entries — or a WP_Error-style message. */
    private static function clean_list( $list ) {
        if ( ! is_array( $list ) || count( $list ) !== 5 ) return 'Please enter exactly 5 jobs';
        $out  = array();
        $seen = array();
        foreach ( $list as $item ) {
            $item = trim( substr( sanitize_text_field( (string) $item ), 0, 80 ) );
            if ( $item === '' ) return 'Please enter exactly 5 jobs';
            $key = strtolower( $item );
            if ( isset( $seen[ $key ] ) ) return 'Each job must be different';
            $seen[ $key ] = true;
            $out[]        = $item;
        }
        return $out;
    }

    /** A ranking must be a re-ordering of the saved items. */
    private static function valid_ranking( $ranking, array $items ): ?array {
        if ( ! is_array( $ranking ) || count( $ranking ) !== 5 ) return null;
        $ranking = array_map( function ( $r ) { return trim( sanitize_text_field( (string) $r ) ); }, $ranking );
        $a = $ranking; $b = $items;
        sort( $a ); sort( $b );
        return $a === $b ? array_values( $ranking ) : null;
    }

    private static function clean_reasons( $reasons, array $ranking ): array {
        $out = array();
        foreach ( $ranking as $job ) {
            $out[ $job ] = is_array( $reasons ) && isset( $reasons[ $job ] ) ? sanitize_textarea_field( (string) $reasons[ $job ] ) : '';
        }
        return $out;
    }

    private static function word_count( string $s ): int {
        $s = trim( $s );
        return $s === '' ? 0 : count( preg_split( '/\s+/', $s ) );
    }

    private function display_name( int $user_id ): string {
        $u = get_userdata( $user_id );
        return $u ? $u->display_name : 'the student';
    }

    private function age_text( int $user_id ): string {
        $age = mfsd_get_student_age( $user_id );
        return $age ? "a {$age}-year-old UK student" : 'a UK student aged 11-14';
    }

    private function profile( int $user_id ): ?array {
        return function_exists( 'mfsd_get_personality_profile' ) ? mfsd_get_personality_profile( $user_id ) : null;
    }

    /** 'ok' | 'none' (show unlock card) | 'test_mode' (hide; admin notice). */
    private function personality_state( int $user_id ): string {
        if ( function_exists( 'mfsd_ptest_is_test_mode' ) && mfsd_ptest_is_test_mode() ) return 'test_mode';
        return $this->profile( $user_id ) ? 'ok' : 'none';
    }

    private function keyword_lists(): array {
        return array(
            mfsd_concern_keyword_list( (string) get_option( self::OPT_URGENT,  mfsd_concern_default_keywords( 'urgent' ) ) ),
            mfsd_concern_keyword_list( (string) get_option( self::OPT_CONCERN, mfsd_concern_default_keywords( 'concern' ) ) ),
        );
    }

    /** Keyword pre-check on every item + reason (before any AI call). */
    private function keyword_hits( array $reasons ): array {
        list( $urgent, $concern ) = $this->keyword_lists();
        $hits = array();
        foreach ( $reasons as $item => $reason ) {
            $hit = mfsd_concern_keyword_check( $item . ' ' . $reason, $urgent, $concern );
            if ( $hit ) $hits[ $item ] = $hit;
        }
        return $hits;
    }

    /** One row per flagged item: trigger keyword / ai / both, highest severity wins. */
    private function raise_flags( int $user_id, array $reasons, array $kw_hits, array $ai_sev ): void {
        foreach ( $reasons as $item => $reason ) {
            $kw = $kw_hits[ $item ] ?? null;
            $ai = $ai_sev[ $item ] ?? '';
            if ( ! $kw && $ai === '' ) continue;
            $trigger  = ( $kw && $ai !== '' ) ? 'both' : ( $kw ? 'keyword' : 'ai' );
            $severity = ( $ai === 'urgent' || ( $kw && $kw['severity'] === 'urgent' ) ) ? 'urgent' : 'concern';
            mfsd_raise_concern_flag( $user_id, self::TASK_SLUG, $item, $reason, $severity, $trigger, $kw ? $kw['matched'] : '' );
        }
    }

    private static function unavailable(): WP_REST_Response {
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'steve_unavailable', 'message' => self::UNAVAILABLE_MSG ), 200 );
    }

    /* ================================================================
       STATUS
       ================================================================ */
    public function handle_status( WP_REST_Request $req ) {
        $viewer_id  = $this->get_current_user_id();
        $student_id = absint( $req->get_param( 'student_id' ) );
        $is_parent  = false;

        if ( $student_id && $student_id !== $viewer_id ) {
            $linked = MFSD_Parent_Tabs::assert_linked( $viewer_id, $student_id );
            if ( is_wp_error( $linked ) ) return $linked;
            $user_id   = $student_id;
            $is_parent = true;
        } else {
            $user_id = $viewer_id;
        }

        if ( ! $user_id ) {
            return new WP_REST_Response( array( 'ok' => true, 'status' => 'not_started' ), 200 );
        }

        $row = $this->get_row( $user_id );
        return new WP_REST_Response( $this->status_payload( $row, $user_id, $is_parent ), 200 );
    }

    /**
     * Everything the child's screens need. Never includes flags, the parent summary,
     * tension/notes or the personality code.
     */
    private function status_payload( ?array $row, int $user_id, bool $is_parent ): array {
        $state   = $this->personality_state( $user_id );
        $profile = $state === 'ok' ? $this->profile( $user_id ) : null;
        $out     = array(
            'ok'                => true,
            'status'            => $row ? $row['status'] : 'not_started',
            'needs_dream_half'  => $this->is_legacy( $row ),
            'personality_state' => ( $state === 'test_mode' && ! current_user_can( 'manage_options' ) ) ? 'hidden' : $state,
            'profile'           => $profile ? array(
                'name'       => $profile['name'],
                'family'     => $profile['family'],
                'avatar_url' => $profile['avatar_url'],
                'strengths'  => $profile['strengths'],
            ) : null,
        );

        if ( $row ) {
            $dream_summaries = array();
            $job_summaries   = array();
            for ( $i = 1; $i <= 5; $i++ ) {
                $dream_summaries[] = (string) ( $row["dream_summary_$i"] ?? '' );
                $job_summaries[]   = (string) ( $row["job_summary_$i"] ?? '' );
            }
            $out += array(
                'dream_items'     => self::dec( $row['dream_items_json'] ),
                'dream_ranking'   => self::dec( $row['dream_ranking_json'] ),
                'dream_reasons'   => (object) self::dec( $row['dream_reasons_json'] ),
                'dream_summaries' => $dream_summaries,
                'fit'             => (object) ( $profile ? self::dec( $row['fit_json'] ) : array() ),
                'jobs'            => self::dec( $row['jobs_json'] ),
                'ranking'         => self::dec( $row['ranking_json'] ),
                'reasons'         => (object) self::dec( $row['reasons_json'] ),
                'intro_text'      => (string) $row['intro_text'],
                'job_summaries'   => $job_summaries,
                'conclusion'      => (string) $row['conclusion'],
                'requirements'    => (object) self::dec( $row['requirements_json'] ),
                'check'           => (object) ( $profile ? self::dec( $row['check_json'] ) : array() ),
                'match'           => (object) self::dec( $row['match_json'] ),
                'also_like'       => $profile ? self::dec( $row['also_like_json'] ) : array(),
                'junk_ready'      => $this->junk_ready( $row ),
            );

            // Completed but AI text missing (e.g. "Save AI summary" off) → the JS regenerates (students only).
            $regen = '';
            if ( $row['status'] === 'completed' && ! $out['needs_dream_half'] && ! $is_parent ) {
                if ( trim( (string) $row['dream_summary_1'] ) === '' ) {
                    $regen = 'dream';
                } elseif ( $row['requirements_json'] === null || ( trim( (string) $row['intro_text'] ) === '' && trim( (string) $row['job_summary_1'] ) === '' ) ) {
                    $regen = 'junk';
                }
            }
            $out['needs_regeneration'] = $regen;
        }

        // Prefill the dream half from old Dream Jobs data (§2).
        if ( ! $is_parent && ( ! $row || empty( $row['dream_items_json'] ) ) ) {
            $prefill = $this->dream_jobs_prefill( $user_id );
            if ( $prefill ) $out['prefill'] = $prefill;
        }

        return $out;
    }

    private function dream_jobs_prefill( int $user_id ): ?array {
        global $wpdb;
        $t = $wpdb->prefix . 'mfsd_ai_dream_jobs_results';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) return null;
        $old = $wpdb->get_row( $wpdb->prepare( "SELECT jobs_json, ranking_json FROM $t WHERE user_id = %d LIMIT 1", $user_id ), ARRAY_A );
        if ( ! $old ) return null;
        $items   = array_values( array_filter( array_map( 'sanitize_text_field', self::dec( $old['jobs_json'] ) ), 'strlen' ) );
        $ranking = self::dec( $old['ranking_json'] );
        if ( ! $items ) return null;
        $items   = array_slice( $items, 0, 5 );
        $ranking = self::valid_ranking( $ranking, $items ) ?: $items;
        return array( 'items' => $items, 'ranking' => $ranking );
    }

    /* ================================================================
       SUBMIT
       ================================================================ */
    public function handle_submit( WP_REST_Request $req ) {
        $user_id = $this->get_current_user_id();

        if ( ! $user_id ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'User not found' ), 400 );
        }

        try {
            $step = sanitize_text_field( (string) $req->get_param( 'step' ) );
            $row  = $this->get_row( $user_id );
            $cm   = function_exists( 'mfsd_set_task_status' ) && get_option( 'ai_junk_jobs_course_management', 1 );

            switch ( $step ) {

                /* ── Dream half ─────────────────────────────────────────── */
                case 'save_dream_items':
                    if ( $row && $row['status'] === 'completed' && ! $this->is_legacy( $row ) ) {
                        return new WP_REST_Response( array( 'ok' => false, 'error' => 'This task is already complete' ), 400 );
                    }
                    $items = self::clean_list( $req->get_param( 'items' ) );
                    if ( ! is_array( $items ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $items ), 400 );
                    $this->save_fields( $user_id, array(
                        'dream_items_json'   => wp_json_encode( $items ),
                        'dream_ranking_json' => wp_json_encode( $items ),
                        'dream_reasons_json' => null,
                        'status'             => 'dream_in_progress',
                    ) );
                    if ( $cm ) mfsd_set_task_status( $user_id, self::TASK_SLUG, 'in_progress' ); // never downgrades
                    return new WP_REST_Response( array( 'ok' => true, 'status' => 'dream_in_progress', 'items' => $items ), 200 );

                case 'save_dream_ranking':
                    $items   = $row ? self::dec( $row['dream_items_json'] ) : array();
                    $ranking = self::valid_ranking( $req->get_param( 'ranking' ), $items );
                    if ( ! $ranking ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'Invalid ranking' ), 400 );
                    $this->save_fields( $user_id, array( 'dream_ranking_json' => wp_json_encode( $ranking ), 'status' => 'dream_reasons' ) );
                    return new WP_REST_Response( array( 'ok' => true, 'status' => 'dream_reasons' ), 200 );

                case 'back_to_dream_input':
                case 'back_to_dream_rank':
                    if ( ! $row || empty( $row['dream_items_json'] ) || ! in_array( $row['status'], array( 'dream_in_progress', 'dream_reasons' ), true ) ) {
                        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Nothing to go back to' ), 400 );
                    }
                    $fields = array( 'dream_reasons_json' => null, 'status' => 'dream_in_progress' );
                    if ( $step === 'back_to_dream_input' ) $fields['dream_ranking_json'] = null;
                    $this->save_fields( $user_id, $fields );
                    return new WP_REST_Response( array( 'ok' => true, 'status' => 'dream_in_progress' ), 200 );

                case 'generate_dream_analysis':
                    return $this->generate_dream_analysis( $user_id, $row, $req );

                /* ── Junk half (existing steps) ─────────────────────────── */
                case 'save_jobs':
                    if ( ! $row || empty( $row['dream_items_json'] ) || ! in_array( $row['status'], array( 'dream_done', 'in_progress', 'reasons' ), true ) ) {
                        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Please finish your dream jobs first' ), 400 );
                    }
                    $jobs = self::clean_list( $req->get_param( 'jobs' ) );
                    if ( ! is_array( $jobs ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $jobs ), 400 );
                    $dream_keys = array_map( 'strtolower', self::dec( $row['dream_items_json'] ) );
                    foreach ( $jobs as $j ) {
                        if ( in_array( strtolower( $j ), $dream_keys, true ) ) {
                            return new WP_REST_Response( array( 'ok' => false, 'error' => '"' . $j . '" is one of your dream jobs — pick a job you would NOT want to do.' ), 400 );
                        }
                    }
                    $this->save_fields( $user_id, array(
                        'jobs_json'    => wp_json_encode( $jobs ),
                        'ranking_json' => wp_json_encode( $jobs ),
                        'reasons_json' => null,
                        'status'       => 'in_progress',
                    ) );
                    if ( $cm ) mfsd_set_task_status( $user_id, self::TASK_SLUG, 'in_progress' );
                    return new WP_REST_Response( array( 'ok' => true, 'status' => 'in_progress', 'jobs' => $jobs ), 200 );

                case 'save_ranking':
                    $jobs    = $row ? self::dec( $row['jobs_json'] ) : array();
                    $ranking = self::valid_ranking( $req->get_param( 'ranking' ), $jobs );
                    if ( ! $ranking || $row['status'] !== 'in_progress' ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'Invalid ranking' ), 400 );
                    $this->save_fields( $user_id, array( 'ranking_json' => wp_json_encode( $ranking ), 'status' => 'reasons' ) );
                    return new WP_REST_Response( array( 'ok' => true, 'status' => 'reasons' ), 200 );

                case 'back_to_input':
                case 'back_to_rank':
                    if ( ! $row || ! in_array( $row['status'], array( 'in_progress', 'reasons' ), true ) ) {
                        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Nothing to go back to' ), 400 );
                    }
                    $fields = array( 'reasons_json' => null, 'status' => 'in_progress' );
                    if ( $step === 'back_to_input' ) $fields['ranking_json'] = null;
                    $this->save_fields( $user_id, $fields );
                    return new WP_REST_Response( array( 'ok' => true, 'status' => 'in_progress' ), 200 );

                case 'generate_analysis':
                    return $this->generate_analysis( $user_id, $row, $req );
            }

            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Invalid step: ' . $step ), 400 );

        } catch ( Exception $e ) {
            error_log( 'Dream & Junk Jobs Submit Error: ' . $e->getMessage() );
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Server error' ), 500 );
        }
    }

    /* ── 5.1 End of dream half: 5 calls ───────────────────────────────── */
    private function generate_dream_analysis( int $user_id, ?array $row, WP_REST_Request $req ) {
        if ( ! $row || empty( $row['dream_items_json'] ) || ! in_array( $row['status'], array( 'dream_reasons', 'dream_done', 'completed' ), true ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Please rank your dream jobs first' ), 400 );
        }
        $items   = self::dec( $row['dream_items_json'] );
        $ranking = self::valid_ranking( $req->get_param( 'ranking' ), $items ) ?: self::dec( $row['dream_ranking_json'] );
        if ( count( $ranking ) !== 5 ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'Please rank your dream jobs first' ), 400 );

        $fresh   = $row['status'] === 'dream_reasons';
        $reasons = $fresh ? self::clean_reasons( $req->get_param( 'reasons' ), $ranking )
                          : self::clean_reasons( self::dec( $row['dream_reasons_json'] ), $ranking );

        $min = mfsd_min_words_for_age( mfsd_get_student_age( $user_id ) );
        foreach ( $reasons as $job => $reason ) {
            if ( $fresh ? self::word_count( $reason ) < $min : trim( $reason ) === '' ) {
                return new WP_REST_Response( array( 'ok' => false, 'error' => "Please write at least {$min} words for \"{$job}\"." ), 400 );
            }
        }

        $kw_hits = $this->keyword_hits( $reasons );
        $bot     = mfsd_steve_chatbot( self::MAP_SUMMARY );
        if ( ! $bot ) {
            // Answers + any keyword flags are kept; no AI output saved, status unchanged.
            $this->save_fields( $user_id, array( 'dream_ranking_json' => wp_json_encode( $ranking ), 'dream_reasons_json' => wp_json_encode( $reasons ) ) );
            $this->raise_flags( $user_id, $reasons, $kw_hits, array() );
            return self::unavailable();
        }

        $name      = $this->display_name( $user_id );
        $age_text  = $this->age_text( $user_id );
        $profile   = $this->profile( $user_id );
        $summaries = array();
        $fit       = array();
        $ai_sev    = array();

        foreach ( $ranking as $i => $job ) {
            $raw = mfsd_steve_query( $bot, AI_Junk_Jobs_AI::dream_prompt( $name, $age_text, $job, $i + 1, $reasons[ $job ], $profile ), $user_id, 'dream ' . ( $i + 1 ) );
            if ( $raw === '' ) {
                $summaries[] = "{$job} is a brilliant thing to aim for, {$name}. Keep exploring what the job involves day to day, and talk to anyone you know who does it.";
                continue;
            }
            $parsed      = AI_Junk_Jobs_AI::parse_dream( $raw, (bool) $profile );
            $summaries[] = $parsed['text'];
            if ( $parsed['fit'] ) $fit[ $job ] = $parsed['fit'];
            if ( $parsed['concern'] !== '' ) $ai_sev[ $job ] = $parsed['concern'];
        }

        $this->raise_flags( $user_id, $reasons, $kw_hits, $ai_sev );

        $new_status = $row['status'] === 'completed' ? 'completed' : 'dream_done';
        $fields     = array(
            'dream_ranking_json' => wp_json_encode( $ranking ),
            'dream_reasons_json' => wp_json_encode( $reasons ),
            'fit_json'           => wp_json_encode( (object) $fit ),
            'mbti_type'          => $profile ? $profile['code'] : null, // server-side only
            'status'             => $new_status,
        );
        if ( get_option( 'ai_junk_jobs_save_summary', 1 ) ) {
            foreach ( $summaries as $i => $s ) $fields[ 'dream_summary_' . ( $i + 1 ) ] = $s;
        }
        $this->save_fields( $user_id, $fields );

        $row = $this->get_row( $user_id );
        return new WP_REST_Response( array(
            'ok'              => true,
            'status'          => $new_status,
            'dream_ranking'   => $ranking,
            'dream_reasons'   => (object) $reasons,
            'dream_summaries' => $summaries,
            'fit'             => (object) ( $profile ? $fit : array() ),
            // Legacy / regeneration: junk half already answered → go straight to the combined analysis.
            'next'            => ( $new_status === 'completed' || $this->junk_ready( $row ) ) ? 'generate_analysis' : 'halfway',
        ), 200 );
    }

    /* ── 5.2 + 5.3 End of junk half: intro + 5 junk + conclusion ──────── */
    private function generate_analysis( int $user_id, ?array $row, WP_REST_Request $req ) {
        if ( ! $row || empty( $row['dream_reasons_json'] ) || ! in_array( $row['status'], array( 'reasons', 'dream_done', 'completed' ), true ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Please finish your dream jobs first' ), 400 );
        }
        $fresh   = $row['status'] === 'reasons';
        $jobs    = self::dec( $row['jobs_json'] );
        $ranking = ( $fresh ? self::valid_ranking( $req->get_param( 'ranking' ), $jobs ) : null ) ?: self::dec( $row['ranking_json'] );
        if ( count( $ranking ) !== 5 ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'Missing ranking or reasons' ), 400 );
        $reasons = $fresh ? self::clean_reasons( $req->get_param( 'reasons' ), $ranking )
                          : self::clean_reasons( self::dec( $row['reasons_json'] ), $ranking );

        $min = mfsd_min_words_for_age( mfsd_get_student_age( $user_id ) );
        foreach ( $reasons as $job => $reason ) {
            if ( $fresh ? self::word_count( $reason ) < $min : trim( $reason ) === '' ) {
                return new WP_REST_Response( array( 'ok' => false, 'error' => "Please write at least {$min} words for \"{$job}\"." ), 400 );
            }
        }

        $kw_hits = $this->keyword_hits( $reasons );
        $bot     = mfsd_steve_chatbot( self::MAP_SUMMARY );
        if ( ! $bot ) {
            $this->save_fields( $user_id, array( 'ranking_json' => wp_json_encode( $ranking ), 'reasons_json' => wp_json_encode( $reasons ) ) );
            $this->raise_flags( $user_id, $reasons, $kw_hits, array() );
            return self::unavailable();
        }

        $name          = $this->display_name( $user_id );
        $age_text      = $this->age_text( $user_id );
        $profile       = $this->profile( $user_id );
        $dream_ranking = self::dec( $row['dream_ranking_json'] );
        $dream_reasons = self::dec( $row['dream_reasons_json'] );
        $fit           = $profile ? self::dec( $row['fit_json'] ) : array();
        $dream_flagged = array_column( mfsd_get_concern_flags( $user_id, self::TASK_SLUG ), 'item_text' );

        $dream_lines_short = array();
        foreach ( $dream_ranking as $i => $dj ) {
            $dream_lines_short[] = AI_Junk_Jobs_AI::dream_line( $dj, $i + 1, $fit[ $dj ] ?? null );
        }

        // 1. Intro
        $intro_text = mfsd_steve_query( $bot, AI_Junk_Jobs_AI::intro_prompt( $name, $age_text ), $user_id, 'intro' );
        $intro_text = $intro_text !== '' ? AI_Junk_Jobs_AI::strip_markers( $intro_text )
            : "Steve says: {$name}, what you don't want to do tells us just as much about you as what you do want. Put the two lists side by side and you start to see what really matters to you. Let's dig in.";

        // 2. Junk jobs
        $job_summaries = array();
        $requirements  = array();
        $check         = array();
        $ai_sev        = array();
        foreach ( $ranking as $i => $job ) {
            $raw = mfsd_steve_query( $bot, AI_Junk_Jobs_AI::junk_prompt( $name, $age_text, $job, $i + 1, $reasons[ $job ], $profile, $dream_lines_short ), $user_id, 'junk ' . ( $i + 1 ) );
            if ( $raw === '' ) {
                $job_summaries[] = "Your feelings about {$job} make complete sense — and they tell us something important about what you DO need from a career.";
                continue;
            }
            $parsed          = AI_Junk_Jobs_AI::parse_junk( $raw, (bool) $profile );
            $job_summaries[] = $parsed['text'];
            if ( $parsed['req'] !== '' )     $requirements[ $job ] = $parsed['req'];
            if ( $parsed['check'] !== '' )   $check[ $job ]        = $parsed['check'];
            if ( $parsed['concern'] !== '' ) $ai_sev[ $job ]       = $parsed['concern'];
        }
        $this->raise_flags( $user_id, $reasons, $kw_hits, $ai_sev );
        $junk_flagged = array_merge( array_keys( $kw_hits ), array_keys( $ai_sev ) );

        // 3. Combined conclusion — flagged entries go in without their reason.
        $dream_lines = array();
        foreach ( $dream_ranking as $i => $dj ) {
            $band   = isset( $fit[ $dj ] ) ? AI_Junk_Jobs_AI::band_label( $fit[ $dj ]['fit'] ) : 'no band';
            $reason = in_array( $dj, $dream_flagged, true ) ? '(handled separately)' : '"' . ( $dream_reasons[ $dj ] ?? '' ) . '"';
            $dream_lines[] = ( $i + 1 ) . ") {$dj} — {$band} — {$reason}";
        }
        $junk_lines = array();
        foreach ( $ranking as $i => $job ) {
            $req_txt = $requirements[ $job ] ?? 'no requirement';
            $chk     = $check[ $job ] ?? 'n/a';
            $reason  = in_array( $job, $junk_flagged, true ) ? '(handled separately)' : '"' . $reasons[ $job ] . '"';
            $junk_lines[] = ( $i + 1 ) . ") {$job} — {$req_txt} — check: {$chk} — {$reason}";
        }
        $req_names = array_values( array_unique( array_values( $requirements ) ) );
        $raw       = mfsd_steve_query( $bot, AI_Junk_Jobs_AI::conclusion_prompt( $name, $age_text, $dream_lines, $junk_lines, $profile, $req_names ), $user_id, 'conclusion' );
        $match     = array();
        if ( $raw !== '' ) {
            $parsed     = AI_Junk_Jobs_AI::parse_conclusion( $raw, $dream_ranking, $req_names );
            $conclusion = $parsed['text'];
            $match      = $parsed['match'];
        } else {
            $conclusion = "These ten choices together paint a clear picture of what you need from a career, {$name}. Keep those requirements in mind as you explore your dream jobs — they're the building blocks of a career that fits who you are.\n\n- Steve";
        }

        // 4. "Jobs people like you often enjoy" — not already in either list.
        $also_like = array();
        if ( $profile ) {
            $taken = array_map( 'strtolower', array_merge( $dream_ranking, $ranking ) );
            foreach ( $profile['careers'] as $c ) {
                $lc = strtolower( $c );
                $dup = false;
                foreach ( $taken as $t ) {
                    if ( $t !== '' && ( strpos( $lc, $t ) !== false || strpos( $t, $lc ) !== false ) ) { $dup = true; break; }
                }
                if ( ! $dup ) $also_like[] = $c;
                if ( count( $also_like ) >= 3 ) break;
            }
        }

        $fields = array(
            'ranking_json'      => wp_json_encode( $ranking ),
            'reasons_json'      => wp_json_encode( $reasons ),
            'requirements_json' => wp_json_encode( (object) $requirements ),
            'check_json'        => wp_json_encode( (object) $check ),
            'match_json'        => wp_json_encode( (object) $match ),
            'also_like_json'    => wp_json_encode( $also_like ),
            'mbti_type'         => $profile ? $profile['code'] : null, // server-side only
            'parent_ai_summary' => null,
            'status'            => 'completed',
        );
        if ( get_option( 'ai_junk_jobs_save_summary', 1 ) ) {
            $fields['intro_text'] = $intro_text;
            foreach ( $job_summaries as $i => $s ) $fields[ 'job_summary_' . ( $i + 1 ) ] = $s;
            $fields['conclusion'] = $conclusion;
        }
        $this->save_fields( $user_id, $fields );

        if ( function_exists( 'mfsd_set_task_status' ) && get_option( 'ai_junk_jobs_course_management', 1 ) ) {
            mfsd_set_task_status( $user_id, self::TASK_SLUG, 'completed' );
        }

        // Return exactly what /status would, with this run's text (covers "Save AI summary" off).
        $payload = $this->status_payload( $this->get_row( $user_id ), $user_id, false );
        $payload['intro_text']         = $intro_text;
        $payload['job_summaries']      = $job_summaries;
        $payload['conclusion']         = $conclusion;
        $payload['needs_regeneration'] = '';
        $payload['chat_context']       = get_option( self::MAP_CHAT, '' ) ? $this->chat_context( $this->get_row( $user_id ), $user_id ) : '';
        return new WP_REST_Response( $payload, 200 );
    }

    /* ================================================================
       7a. CAREERS CHAT CONTEXT — no personality code, ever
       ================================================================ */
    private function chat_context( array $row, int $user_id ): string {
        $profile  = $this->profile( $user_id );
        $fit      = $profile ? self::dec( $row['fit_json'] ) : array();
        $dreams   = array();
        foreach ( self::dec( $row['dream_ranking_json'] ) as $i => $dj ) {
            $band     = isset( $fit[ $dj ] ) ? ': ' . strtolower( AI_Junk_Jobs_AI::band_label( $fit[ $dj ]['fit'] ) ) : '';
            $dreams[] = ( $i + 1 ) . ') ' . $dj . $band;
        }
        $reqs = array_values( self::dec( $row['requirements_json'] ) );
        $junk = self::dec( $row['ranking_json'] );
        $age  = mfsd_get_student_age( $user_id );

        $ctx  = 'Student context for Dream & Junk Jobs. Dream jobs in order: ' . implode( ', ', $dreams ) . '. ';
        $ctx .= 'What they need from a career: ' . ( $reqs ? implode( ', ', $reqs ) : 'not yet clear' ) . '. ';
        $ctx .= 'Jobs they want to avoid: ' . implode( ', ', $junk ) . '. ';
        if ( $profile ) {
            $ctx .= "Personality: {$profile['name']} ({$profile['family']} family) — strengths: {$profile['strengths']}. ";
        }
        $ctx .= 'Answer career questions for a UK student aged ' . ( $age ?: '11-14' ) . '. Relate answers to their dream jobs and needs. ';
        $ctx .= "Keep answers to 2-4 sentences. Never use personality codes, 'MBTI' or 'Myers-Briggs'.";
        return mfsd_steve_chat_context( $ctx );
    }

    /* ================================================================
       7. PARENT VIEW
       ================================================================ */
    public function handle_parent_summary( WP_REST_Request $req ) {
        $viewer_id  = $this->get_current_user_id();
        $student_id = absint( $req->get_param( 'student_id' ) );
        $linked     = MFSD_Parent_Tabs::assert_linked( $viewer_id, $student_id );
        if ( is_wp_error( $linked ) ) return $linked;

        return new WP_REST_Response( array(
            'ok'            => true,
            'html'          => $this->parent_panel_html( $student_id, $viewer_id, true ),
            'has_open_flag' => ! empty( mfsd_get_concern_flags( $student_id, self::TASK_SLUG, true ) ),
        ), 200 );
    }

    /**
     * Parent tab HTML. With $allow_generate false (page render) only a cached summary is used;
     * otherwise a placeholder lets the JS fetch /parent-summary (which may call SteveGPT).
     */
    private function parent_panel_html( int $student_id, int $viewer_id, bool $allow_generate ): string {
        $row        = $this->get_row( $student_id );
        $child_name = MFSD_Parent_Tabs::child_first_name( $student_id );
        $flags      = mfsd_get_concern_flags( $student_id, self::TASK_SLUG, true );
        $fallback   = MFSD_Parent_Tabs::concern_fallback_html( $flags, $child_name );
        $lead       = $flags ? MFSD_Parent_Tabs::CONCERN_TITLE : '';

        if ( ! $row || $row['status'] !== 'completed' || $this->is_legacy( $row ) ) {
            return '<div class="mfsd-pt__parent">' . $fallback
                 . '<p>' . esc_html( $child_name ) . ' hasn\'t finished Dream &amp; Junk Jobs yet. Steve\'s parent summary will appear here once they have.</p></div>';
        }

        if ( ! $allow_generate && trim( (string) $row['parent_ai_summary'] ) === '' ) {
            return '<div class="mfsd-pt__loading" id="ai-junk-jobs-parent-root">Steve is preparing your parent summary…</div>';
        }

        $table = $this->table();
        $text  = MFSD_Parent_Tabs::get_or_generate_parent_summary( $table, $student_id, function () use ( $row, $student_id, $viewer_id, $flags ) {
            $bot = mfsd_steve_chatbot( self::MAP_PARENT );
            if ( ! $bot ) return '';
            $raw = mfsd_steve_query( $bot, $this->parent_prompt( $row, $student_id, $flags ), $viewer_id, 'parent summary' );
            if ( $raw === '' ) return '';
            $note = MFSD_Parent_Tabs::extract_parent_note( $raw );
            if ( $note !== '' ) mfsd_concern_save_parent_note( $student_id, self::TASK_SLUG, $note );
            // Keep [SECTION:] headings; drop any other stray markers.
            return trim( preg_replace( '/\[\s*(?!SECTION\b)[A-Z_]{2,}\s*:[^\]]*\]/i', '', $raw ) );
        } );

        if ( $text === '' ) {
            return '<div class="mfsd-pt__parent">' . $fallback
                 . '<p>Steve\'s parent summary isn\'t available right now. Please try again in a few minutes.</p></div>';
        }
        return MFSD_Parent_Tabs::build_parent_html( $text, $lead, $fallback );
    }

    private function parent_prompt( array $row, int $student_id, array $flags ): string {
        $child    = MFSD_Parent_Tabs::child_first_name( $student_id );
        $age      = mfsd_get_student_age( $student_id );
        $profile  = $this->profile( $student_id );
        $fit      = $profile ? self::dec( $row['fit_json'] ) : array();
        $reqs     = self::dec( $row['requirements_json'] );
        $check    = $profile ? self::dec( $row['check_json'] ) : array();
        $match    = self::dec( $row['match_json'] );
        $dreason  = self::dec( $row['dream_reasons_json'] );
        $jreason  = self::dec( $row['reasons_json'] );

        $p  = "You are SteveGPT, Steve Sallis's coach, writing for the PARENT of {$child}" . ( $age ? ", aged {$age}" : '' ) . ". ";
        $p .= "Write in clear, warm adult language (not student language), UK English, about 300 words. ";
        $p .= "Don't assume anything about family structure, money or circumstances. Never use personality codes, 'MBTI' or 'Myers-Briggs'.\n\n";
        $p .= "Their child has just completed Dream & Junk Jobs: 5 dream jobs and 5 jobs they would not want, with reasons.\n\n";
        $p .= "DREAM JOBS (ranked):\n";
        foreach ( self::dec( $row['dream_ranking_json'] ) as $i => $dj ) {
            $band  = isset( $fit[ $dj ] ) ? AI_Junk_Jobs_AI::band_label( $fit[ $dj ]['fit'] ) : 'no band';
            $ticks = isset( $match[ $dj ] ) ? ' — meets: ' . ( implode( ', ', $match[ $dj ]['met'] ) ?: 'none' ) . '; missing: ' . ( implode( ', ', $match[ $dj ]['missing'] ) ?: 'none' ) : '';
            $p .= ( $i + 1 ) . ") {$dj} ({$band}){$ticks}. Reason: \"" . ( $dreason[ $dj ] ?? '' ) . "\"\n";
        }
        $p .= "\nJUNK JOBS (ranked):\n";
        foreach ( self::dec( $row['ranking_json'] ) as $i => $job ) {
            $p .= ( $i + 1 ) . ") {$job} — requirement: " . ( $reqs[ $job ] ?? 'none' ) . ( isset( $check[ $job ] ) ? " — personality check: {$check[$job]}" : '' )
                . ". Reason: \"" . ( $jreason[ $job ] ?? '' ) . "\"\n";
        }
        if ( $profile ) {
            $p .= "\nPERSONALITY (Who Am I): {$profile['name']} ({$profile['family']} family). Strengths: {$profile['strengths']}.\n";
        }

        $p .= "\nUse these section markers, in this order:\n";
        if ( $flags ) {
            $urgent = in_array( 'urgent', array_column( $flags, 'severity' ), true );
            $p .= "[SECTION:Something to be aware of] (ALWAYS FIRST) — {$child} wrote the following, which may be worth a gentle conversation:\n";
            foreach ( $flags as $f ) {
                $p .= "- {$f['item_text']}: \"{$f['reason_text']}\"\n";
            }
            $p .= "Quote what they wrote. Stay calm and don't diagnose. Give 2-3 ways to start the conversation.";
            if ( $urgent ) $p .= " Because this may be serious, name GP, NHS 111, 999 in an emergency, Childline and the NSPCC helpline.";
            $p .= "\n";
        }
        $p .= "[SECTION:What their choices tell you]\n";
        $p .= $profile ? "[SECTION:How their personality fits] — use the character name and fit bands, never a code\n" : '';
        $p .= "[SECTION:Conversation starters] — 3 open questions\n";
        $p .= "[SECTION:How you can help] — work experience, people to talk to, taster days; all low-cost\n";
        $p .= "Sign off \"- Steve\". Then on a final line output PARENT_NOTE: followed by one line for the parent about any concern, or PARENT_NOTE: none";
        return $p;
    }

    private function get_current_user_id() {
        return (int) get_current_user_id();
    }

    /* ================================================================
       ADMIN
       ================================================================ */
    public function admin_menu() {
        add_menu_page( 'Dream & Junk Jobs', 'Dream & Junk Jobs', 'manage_options', 'ai-junk-jobs',
            array( $this, 'admin_page' ), 'dashicons-hammer', 31 );
    }

    private function half_label( $s ): string {
        if ( empty( $s->dream_items_json ) && in_array( $s->status, array( 'in_progress', 'reasons', 'completed' ), true ) ) return 'Legacy – needs dream half';
        if ( $s->status === 'completed' ) return 'Done';
        if ( in_array( $s->status, array( 'in_progress', 'reasons', 'dream_done' ), true ) ) return 'Junk';
        return 'Dream';
    }

    public function admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ( isset( $_POST['action'] ) && $_POST['action'] === 'save_jj_settings'
             && check_admin_referer( 'ai_junk_jobs_settings' ) ) {
            update_option( 'ai_junk_jobs_course_management', isset( $_POST['course_management'] ) ? 1 : 0 );
            update_option( 'ai_junk_jobs_save_summary',      isset( $_POST['save_summary'] )      ? 1 : 0 );
            update_option( self::OPT_URGENT,  sanitize_textarea_field( wp_unslash( $_POST['urgent_keywords'] ?? '' ) ) );
            update_option( self::OPT_CONCERN, sanitize_textarea_field( wp_unslash( $_POST['concern_keywords'] ?? '' ) ) );
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        if ( isset( $_POST['action'] ) && $_POST['action'] === 'reset_jj_student'
             && check_admin_referer( 'ai_junk_jobs_reset_student' ) ) {
            $reset_uid = intval( $_POST['reset_user_id'] ?? 0 );
            if ( $reset_uid > 0 ) {
                // Clears both halves (one row). Concern flags are kept.
                $wpdb->delete( $table, array( 'user_id' => $reset_uid ) );
                if ( function_exists( 'mfsd_get_task_order_row' ) ) {
                    $wpdb->delete( $wpdb->prefix . 'mfsd_task_progress',
                        array( 'student_id' => $reset_uid, 'task_slug' => self::TASK_SLUG ) );
                }
                $u    = get_user_by( 'id', $reset_uid );
                $name = $u ? $u->display_name : 'Student';
                echo '<div class="notice notice-success"><p>' . esc_html( $name ) . '\'s Dream &amp; Junk Jobs data (both halves) and progress have been reset. Concern flags were kept.</p></div>';
            }
        }

        if ( isset( $_POST['action'] ) && $_POST['action'] === 'regen_jj_parent'
             && check_admin_referer( 'ai_junk_jobs_regen_parent' ) ) {
            $uid = intval( $_POST['regen_user_id'] ?? 0 );
            if ( $uid > 0 ) {
                MFSD_Parent_Tabs::clear_parent_summary( $table, $uid );
                echo '<div class="notice notice-success"><p>Parent summary cleared — it will be regenerated the next time a parent opens it.</p></div>';
            }
        }

        $cm           = get_option( 'ai_junk_jobs_course_management', 1 );
        $save_summary = get_option( 'ai_junk_jobs_save_summary', 1 );
        $urgent_kw    = get_option( self::OPT_URGENT,  mfsd_concern_default_keywords( 'urgent' ) );
        $concern_kw   = get_option( self::OPT_CONCERN, mfsd_concern_default_keywords( 'concern' ) );
        $missing      = mfsd_steve_missing_mappings( array( self::MAP_SUMMARY, self::MAP_PARENT, self::MAP_CHAT ) );
        $students     = $wpdb->get_results(
            "SELECT j.user_id, j.status, j.intro_text, j.analysis, j.dream_items_json, j.dream_summary_1, j.parent_ai_summary, u.display_name
             FROM $table j JOIN {$wpdb->users} u ON u.ID = j.user_id
             ORDER BY u.display_name ASC"
        );
        ?>
        <div class="wrap">
            <h1>⚒️ Dream &amp; Junk Jobs Settings</h1>

            <?php if ( ! function_exists( 'mfsd_get_task_status' ) ): ?>
                <div class="notice notice-warning">
                    <p><strong>MFSD Ordering Utility</strong> is not active — course management features will not function until it is activated.</p>
                </div>
            <?php endif; ?>
            <?php if ( ! class_exists( 'SteveGPT_Chatbot' ) ): ?>
                <div class="notice notice-error"><p><strong>SteveGPT is not active.</strong> Students will see the "quick break" message and can't complete the task.</p></div>
            <?php elseif ( $missing ): ?>
                <div class="notice notice-error"><p><strong>SteveGPT mapping not configured:</strong> <code><?php echo esc_html( implode( '</code>, <code>', $missing ) ); ?></code> (SteveGPT → Integrations).</p></div>
            <?php endif; ?>
            <?php if ( function_exists( 'mfsd_ptest_is_test_mode' ) && mfsd_ptest_is_test_mode() ): ?>
                <div class="notice notice-warning"><p>Personality data off: Who Am I is in test mode.</p></div>
            <?php endif; ?>

            <h2>Settings</h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'ai_junk_jobs_settings' ); ?>
                <input type="hidden" name="action" value="save_jj_settings">
                <table class="form-table">
                    <tr>
                        <th>Course Management</th>
                        <td>
                            <label><input type="checkbox" name="course_management" value="1" <?php checked( $cm, 1 ); ?>>
                            <strong>Enable course ordering &amp; completion tracking</strong></label>
                        </td>
                    </tr>
                    <tr>
                        <th>Save AI Summary</th>
                        <td>
                            <label><input type="checkbox" name="save_summary" value="1" <?php checked( $save_summary, 1 ); ?>>
                            <strong>Save AI summary to database</strong></label>
                            <p class="description">When on: all 13 AI segments (5 dream jobs, intro, 5 junk jobs, conclusion and the parent summary) are saved and reused on return visits. When off: regenerated each visit — useful for testing prompts.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Urgent keywords</th>
                        <td>
                            <textarea name="urgent_keywords" rows="6" cols="60"><?php echo esc_textarea( $urgent_kw ); ?></textarea>
                            <p class="description">One per line, whole word, case-insensitive. A match raises an <strong>urgent</strong> concern flag for the parent.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Concern keywords</th>
                        <td>
                            <textarea name="concern_keywords" rows="8" cols="60"><?php echo esc_textarea( $concern_kw ); ?></textarea>
                            <p class="description">One per line. A match raises a <strong>concern</strong> flag ("worth a look"). Junk-job reasons often mention family — review with that in mind.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" class="button button-primary" value="Save Settings"></p>
            </form>

            <hr>
            <h2>Student Records</h2>
            <?php if ( empty( $students ) ): ?>
                <p style="color:#999;">No students have started Dream &amp; Junk Jobs yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Student</th><th>Half</th><th>Status</th><th>Summary Saved</th><th>Parent Summary</th><th>Ordering Status</th></tr></thead>
                    <tbody>
                        <?php foreach ( $students as $s ):
                            $order_status = function_exists( 'mfsd_get_task_status' ) ? mfsd_get_task_status( $s->user_id, self::TASK_SLUG ) : '—';
                            $has_summary  = ! empty( $s->intro_text ) || ! empty( $s->dream_summary_1 ) || ! empty( $s->analysis );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $s->display_name ); ?></strong></td>
                            <td><?php echo esc_html( $this->half_label( $s ) ); ?></td>
                            <td><?php echo esc_html( $s->status ); ?></td>
                            <td><?php echo $has_summary ? '✓ Yes' : '✗ No'; ?></td>
                            <td><?php echo ! empty( $s->parent_ai_summary ) ? '✓ Cached' : '—'; ?></td>
                            <td><?php echo esc_html( $order_status ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( ! empty( $students ) ): ?>
            <hr>
            <h2>Regenerate Parent Summary</h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'ai_junk_jobs_regen_parent' ); ?>
                <input type="hidden" name="action" value="regen_jj_parent">
                <select name="regen_user_id" style="min-width:260px;">
                    <option value="">— select a student —</option>
                    <?php foreach ( $students as $s ): ?>
                        <option value="<?php echo esc_attr( $s->user_id ); ?>"><?php echo esc_html( $s->display_name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" class="button" value="Regenerate">
            </form>
            <?php endif; ?>

            <hr>
            <h2>Reset Student Answers</h2>
            <?php if ( empty( $students ) ): ?>
                <p style="color:#999;">No students have started Dream &amp; Junk Jobs yet.</p>
            <?php else: ?>
            <form method="post" action="" onsubmit="return confirm('Permanently delete all Dream & Junk Jobs data (both halves) for this student? Concern flags are kept.');">
                <?php wp_nonce_field( 'ai_junk_jobs_reset_student' ); ?>
                <input type="hidden" name="action" value="reset_jj_student">
                <table class="form-table">
                    <tr>
                        <th><label for="reset_user_id">Select Student</label></th>
                        <td>
                            <select name="reset_user_id" id="reset_user_id" style="min-width:260px;">
                                <option value="">— select a student —</option>
                                <?php foreach ( $students as $s ):
                                    $os = function_exists( 'mfsd_get_task_status' ) ? ' — ' . mfsd_get_task_status( $s->user_id, self::TASK_SLUG ) : '';
                                ?>
                                <option value="<?php echo esc_attr( $s->user_id ); ?>">
                                    <?php echo esc_html( $s->display_name . ' (' . $s->status . $os . ')' ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" class="button button-secondary" value="Reset Selected Student" style="color:#d63638;border-color:#d63638;">
                </p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }
}

new AI_Junk_Jobs();
