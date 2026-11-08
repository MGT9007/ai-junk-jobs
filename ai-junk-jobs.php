<?php
/**
 * Plugin Name: AI Dream & Junk Jobs
 * Description: Week 2 Task 2. Students enter, rank and explain 5 dream jobs and 5 junk jobs, then get a combined SteveGPT results screen with personality fit, requirement ticks, plot twists and a careers chat. Use shortcode [ai_junk_jobs] (alias [ai_dream_junk_jobs]).
 * Version: 7.0.1
 * Author: MisterT9007
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/mfsd-shared-helpers.php'; // concern flags now live in mfsd-safeguarding
require_once __DIR__ . '/includes/class-mfsd-parent-tabs.php';
require_once __DIR__ . '/includes/class-junk-jobs-ai.php';

class AI_Junk_Jobs {
    const VERSION      = '7.0.1';
    const TABLE        = 'mfsd_ai_junk_jobs_results';
    const GUESS_TABLE  = 'mfsd_djj_guesses';              // 7.0.0 parent guessing game (spec §16)
    const ACTION_KINDS = array( 'skill', 'explore', 'habit', 'own' );
    const DECK_SIZE    = 14;
    const DEFAULT_DECOYS = "Accountant\nActor\nAirline Pilot\nArchitect\nArtist\nBaker\nBanker\nBarista\nBuilder\nBus Driver\nCarpenter\nChef\nCleaner\nComedian\nDentist\nDoctor\nElectrician\nEngineer\nEstate Agent\nFarmer\nFirefighter\nFlorist\nGardener\nHairdresser\nJournalist\nLawyer\nLibrarian\nLifeguard\nMechanic\nMusician\nNurse\nOptician\nParamedic\nPharmacist\nPhotographer\nPlumber\nPolice Officer\nPostal Worker\nScientist\nShop Assistant\nSocial Worker\nSoftware Developer\nTeacher\nTour Guide\nTranslator\nVet\nWeb Designer\nZookeeper";

    /** @var AI_Junk_Jobs|null the running instance, for mfsd_get_dream_junk_jobs() */
    public static $inst = null;
    const NONCE_ACTION = 'wp_rest';
    const TASK_SLUG    = 'junk_jobs';

    const MAP_SUMMARY  = 'mfsd_stevegpt_map_junk_jobs_summary';
    const MAP_PARENT   = 'mfsd_stevegpt_map_junk_jobs_parent_summary';
    const MAP_CHAT     = 'mfsd_stevegpt_map_junk_jobs_chat';

    // Old per-task keyword options (ai_junk_jobs_urgent_keywords / _concern_keywords) are no longer read:
    // MFSD Safeguarding migrated them into its central word list on activation.

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
        // 7.0.0 integrations (spec §15.3)
        add_filter( 'mfsd_commitment_sources', array( $this, 'register_commitment_source' ) );
        add_filter( 'mfsd_commitment_visible_to_parent', array( $this, 'commitment_visible_to_parent' ), 10, 3 );
        add_filter( 'mfsd_quest_task_extra_badges', array( $this, 'register_extra_badges' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        self::$inst = $this;
    }

    public function register_commitment_source( $sources ) {
        $sources = is_array( $sources ) ? $sources : array();
        $sources[ self::TASK_SLUG ] = array(
            'label'  => 'Dream & Junk Jobs',
            'kind'   => 'Action',
            'icon'   => '⚒️',
            'colour' => '#9333EA',
            'url'    => home_url( '/my-future-self-foundation-course/week-2/junk-jobs/' ),
        );
        return $sources;
    }

    /** A junk_jobs card stays hidden from a parent until THAT parent has finished the guessing game (§15.3). */
    public function commitment_visible_to_parent( $visible, $card, $parent_id ) {
        if ( ! $visible || ! is_array( $card ) || ( $card['source_task'] ?? '' ) !== self::TASK_SLUG ) return $visible;
        return $this->parent_has_played( (int) $parent_id, (int) ( $card['user_id'] ?? 0 ) );
    }

    public function register_extra_badges( $map ) {
        $map = is_array( $map ) ? $map : array();
        $map[ self::TASK_SLUG ] = array_values( array_unique( array_merge( (array) ( $map[ self::TASK_SLUG ] ?? array() ), array( 'badge_dream_jobs' ) ) ) );
        return $map;
    }

    public function admin_assets( $hook ) {
        if ( strpos( (string) $hook, 'ai-junk-jobs' ) === false ) return;
        wp_enqueue_media();
    }

    public function register_stevegpt_slots( array $slots ): array {
        $slots[] = array( 'plugin' => 'Dream & Junk Jobs', 'role' => 'Summary (dream + junk + conclusion)', 'option' => self::MAP_SUMMARY, 'tokens' => array(), 'task_slug' => self::TASK_SLUG );
        $slots[] = array( 'plugin' => 'Dream & Junk Jobs', 'role' => 'Parent summary',                      'option' => self::MAP_PARENT,  'tokens' => array(), 'task_slug' => self::TASK_SLUG, 'audience' => 'parent' );
        $slots[] = array( 'plugin' => 'Dream & Junk Jobs', 'role' => 'Careers chatbot',                     'option' => self::MAP_CHAT,    'tokens' => array(), 'task_slug' => self::TASK_SLUG );
        return $slots;
    }

    /** A new flag means the cached parent summary must lead with it next time. */
    public function on_flag_raised( $flag_id, $user_id, $source_task ) {
        if ( $source_task === 'commitments' ) {
            // Card flags only matter when they're on this task's card (re-review N1).
            $row  = $this->get_row( (int) $user_id );
            $flag = class_exists( 'MFSD_SG_Flags' ) ? MFSD_SG_Flags::get_one( (int) $flag_id ) : null;
            if ( ! $row || ! (int) $row['commitment_id'] || ! $flag || (string) $flag['source_ref'] !== (string) (int) $row['commitment_id'] ) return;
        } elseif ( $source_task !== self::TASK_SLUG ) {
            return;
        }
        global $wpdb;
        MFSD_Parent_Tabs::clear_parent_summary( $wpdb->prefix . self::TABLE, (int) $user_id );
    }

    public function check_version() {
        $saved_version = get_option( 'ai_junk_jobs_version' );
        if ( $saved_version !== self::VERSION ) {
            $this->maybe_upgrade_table();
            if ( function_exists( 'mfsd_concern_flags_install' ) ) mfsd_concern_flags_install();
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

        // v7.0.0 — commitment + guessing game (spec §4.1)
        $v7 = array(
            'actions_json'    => 'LONGTEXT NULL',
            'chosen_action'   => 'VARCHAR(10) NULL',
            'commitment_id'   => 'BIGINT UNSIGNED NULL',
            'commitment_text' => 'VARCHAR(140) NULL',
            'decoys_json'     => 'LONGTEXT NULL',
            'completed_at'    => 'DATETIME NULL', // set once when the commitment finishes the task (updated_at moves on later saves)
        );
        foreach ( $v7 as $col => $type ) {
            if ( ! in_array( $col, $columns ) ) {
                $wpdb->query( "ALTER TABLE $table ADD COLUMN $col $type" );
            }
        }
        $this->create_guess_table();
    }

    /** wp_mfsd_djj_guesses (spec §4.3). Column names are read by Parent Portal 5.0.0 — keep them. */
    private function create_guess_table() {
        global $wpdb;
        $t       = $wpdb->prefix . self::GUESS_TABLE;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned NOT NULL,
  child_id bigint(20) unsigned NOT NULL,
  deck_json longtext NULL,
  answers_json longtext NULL,
  score tinyint(3) unsigned NULL,
  coins_awarded smallint(5) unsigned NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  completed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY parent_child (parent_id,child_id),
  KEY idx_child (child_id)
) $charset;" );
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
            actions_json       LONGTEXT NULL,
            chosen_action      VARCHAR(10) NULL,
            commitment_id      BIGINT UNSIGNED NULL,
            commitment_text    VARCHAR(140) NULL,
            decoys_json        LONGTEXT NULL,
            completed_at       DATETIME NULL,
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
        $this->create_guess_table();
        if ( function_exists( 'mfsd_concern_flags_install' ) ) mfsd_concern_flags_install();
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
        // Safeguarding is required: without it, free text can't be checked, so the task is closed.
        if ( ! function_exists( 'mfsd_safeguarding_check' ) ) {
            return '<p style="text-align:center;padding:40px;color:#555;">' . esc_html( self::UNAVAILABLE_MSG ) . '</p>';
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
            $row        = $this->get_row( $student_id );
            $gated      = $this->parent_gated( $viewer_id, $student_id, $row );
            $this->add_config( $handle, array(
                'parentView'    => true,
                'parentGame'    => $gated,
                'studentId'     => $student_id,
                'childName'     => $child_name,
                'restUrlParent' => esc_url_raw( rest_url( 'ai-junk-jobs/v1/parent-summary' ) ),
            ) );

            // §17.6 gate: before this parent has played, no tabs and no jobs or summary in the page —
            // only urgent concerns (incl. released moderation) above the game.
            if ( $gated ) {
                $urgent = $this->urgent_parent_flags( $student_id );
                return '<div class="mfsd-pt cq-parent-gate">'
                     . ( $urgent ? MFSD_Parent_Tabs::concern_fallback_html( $urgent, $child_name ) : '' )
                     . '<div id="ai-junk-jobs-root"></div></div>';
            }

            return MFSD_Parent_Tabs::render_tabs(
                '<div id="ai-junk-jobs-root"></div>',
                $this->parent_panel_html( $student_id, $viewer_id, false ),
                ! empty( $this->parent_flags( $student_id ) ),
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
            'restUrlGuess'  => esc_url_raw( rest_url( 'ai-junk-jobs/v1/guess' ) ),
            'halfwayVideo'  => $this->halfway_video(),
            'parentGame'    => false,
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
        // Guessing game (§16.4) — linked parents only, checked in each handler.
        register_rest_route( 'ai-junk-jobs/v1', '/guess', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_guess_get' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ) );
        register_rest_route( 'ai-junk-jobs/v1', '/guess/answer', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_guess_answer' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ) );
        register_rest_route( 'ai-junk-jobs/v1', '/guess/finish', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_guess_finish' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ) );
    }

    public function check_permission( WP_REST_Request $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'unauthorized', 'You must be logged in', array( 'status' => 401 ) );
        }
        if ( ! function_exists( 'mfsd_safeguarding_check' ) ) {
            return new WP_Error( 'safeguarding_unavailable', self::UNAVAILABLE_MSG, array( 'status' => 503 ) );
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

    /** 7.0.1: the student's first name (first_name meta, else the first word of the display name), as the style guide requires. */
    private function display_name( int $user_id ): string {
        $first = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
        if ( $first === '' ) { $u = get_userdata( $user_id ); $first = $u ? trim( (string) strtok( (string) $u->display_name, ' ' ) ) : ''; }
        return $first !== '' ? $first : 'the student';
    }

    private function age_text( int $user_id ): string {
        $age = mfsd_get_student_age( $user_id );
        return $age ? "a {$age}-year-old UK student" : 'a UK student aged 10-14';
    }

    private function profile( int $user_id ): ?array {
        return function_exists( 'mfsd_get_personality_profile' ) ? mfsd_get_personality_profile( $user_id ) : null;
    }

    /** 'ok' | 'none' (show unlock card) | 'test_mode' (hide; admin notice). */
    private function personality_state( int $user_id ): string {
        if ( function_exists( 'mfsd_ptest_is_test_mode' ) && mfsd_ptest_is_test_mode() ) return 'test_mode';
        return $this->profile( $user_id ) ? 'ok' : 'none';
    }

    /**
     * Keyword pre-check on every item + reason, before any AI call (spec §5.4). mfsd_safeguarding_check()
     * raises the keyword flag itself; the AI flag is raised by SteveGPT 14 from the call's context.
     * @return array item => ['severity','matched',...]
     */
    private function keyword_hits( int $user_id, array $reasons, string $half ): array {
        $hits = array();
        foreach ( $reasons as $item => $reason ) {
            $hit = mfsd_safeguarding_check( $user_id, self::TASK_SLUG, (string) $item, (string) $reason,
                array( 'field' => 'reason', 'source_ref' => 'djj:' . $half ) );
            if ( $hit ) $hits[ $item ] = $hit;
        }
        return $hits;
    }

    /** Safeguarding context for one SteveGPT call (Prompt Layer §3.1). */
    private function sg_ctx( string $kind, int $child_id, array $extra = array() ): array {
        return array_merge( array( 'kind' => $kind, 'source_task' => self::TASK_SLUG, 'user_id' => $child_id ), $extra );
    }

    /** Severity SteveGPT 14 found in the last reply ('' if none, or an older SteveGPT). */
    private static function sg_severity( $bot ): string {
        return ( $bot && method_exists( $bot, 'last_safeguarding_severity' ) ) ? (string) $bot->last_safeguarding_severity() : '';
    }

    /**
     * Items with an unreleased MODERATION flag in this task. Their words (and Steve's reply to them)
     * must never reach a parent — not in the parent summary prompt, not in the "{Child}'s view" tab —
     * until an admin releases the flag (Safeguarding spec, moderation tier).
     */
    private function withheld_items( int $user_id ): array {
        if ( ! function_exists( 'mfsd_get_concern_flags' ) ) return array();
        $out = array();
        foreach ( mfsd_get_concern_flags( $user_id, self::TASK_SLUG, false, true ) as $f ) {
            if ( ( $f['severity'] ?? '' ) === 'moderation' && empty( $f['released_at'] ) ) $out[] = (string) $f['item_text'];
        }
        return array_values( array_unique( $out ) );
    }

    const WITHHELD_TEXT = '(not shown)';

    /**
     * 7.0.0 (review B1): the commitment text is held back from parents when EITHER this task's
     * "Commitment" item is withheld (Commitments inactive) OR the card itself is withheld —
     * mfsd-commitments checks card text under its own source ('commitments', card id).
     */
    private function commitment_withheld( int $user_id, ?array $row ): bool {
        if ( in_array( 'Commitment', $this->withheld_items( $user_id ), true ) ) return true;
        $cid = (int) ( $row['commitment_id'] ?? 0 );
        return $cid > 0 && function_exists( 'mfsd_safeguarding_is_withheld' )
            && mfsd_safeguarding_is_withheld( $user_id, 'commitments', (string) $cid );
    }

    /** Parent-visible flags that are still open (status not closed) — unreleased moderation excluded (§6). */
    private function parent_flags( int $student_id ): array {
        if ( ! function_exists( 'mfsd_get_concern_flags' ) ) return array();
        $not_closed = function ( $f ) { return ( $f['status'] ?? 'open' ) !== 'closed'; };
        $flags = array_values( array_filter( mfsd_get_concern_flags( $student_id, self::TASK_SLUG, false, false ), $not_closed ) );
        // Flags Commitments raised on this task's card (source 'commitments', ref = card id) belong here too (review B1).
        $row = $this->get_row( $student_id );
        $cid = $row ? (int) $row['commitment_id'] : 0;
        if ( $cid ) {
            foreach ( array_filter( mfsd_get_concern_flags( $student_id, 'commitments', false, false ), $not_closed ) as $f ) {
                if ( (string) ( $f['source_ref'] ?? '' ) === (string) $cid ) $flags[] = $f;
            }
        }
        return $flags;
    }

    /** Open parent-visible flags that count as urgent: urgent, or released moderation (§6). */
    private function urgent_parent_flags( int $student_id ): array {
        return array_values( array_filter( $this->parent_flags( $student_id ), function ( $f ) {
            return in_array( $f['severity'] ?? '', array( 'urgent', 'moderation' ), true );
        } ) );
    }

    /** Lower-cased items with ANY open flag, withheld included — left out of the guessing deck (§16.1). */
    private function open_flag_items( int $student_id ): array {
        if ( ! function_exists( 'mfsd_get_concern_flags' ) ) return array();
        $out = array();
        foreach ( mfsd_get_concern_flags( $student_id, self::TASK_SLUG, false, true ) as $f ) {
            if ( ( $f['status'] ?? 'open' ) !== 'closed' ) $out[] = strtolower( trim( (string) $f['item_text'] ) );
        }
        return array_values( array_unique( $out ) );
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
        // Parent: progress only until the child has completed AND this parent has played (§7, §16.4).
        if ( $is_parent && ( ! $row || $row['status'] !== 'completed' || $this->is_legacy( $row ) || ! $this->parent_has_played( $viewer_id, $user_id ) ) ) {
            return new WP_REST_Response( array(
                'ok'               => true,
                'status'           => $row ? $row['status'] : 'not_started',
                'needs_dream_half' => $this->is_legacy( $row ),
                'gated'            => $this->parent_gated( $viewer_id, $user_id, $row ),
            ), 200 );
        }
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
                // 7.0.0 commitment (§15)
                'actions'            => (object) self::dec( $row['actions_json'] ?? '' ),
                'chosen_action'      => (string) ( $row['chosen_action'] ?? '' ),
                'commitment_text'    => (string) ( $row['commitment_text'] ?? '' ),
                'commitment'         => $this->commitment_card( $row ),
                'commitments_active' => function_exists( 'mfsd_create_commitment' ),
                'guesses'            => $is_parent ? array() : $this->guess_lines( $user_id ),
            );

            // Completed but AI text missing (e.g. "Save AI summary" off) → the JS regenerates (students only).
            $regen = '';
            if ( in_array( $row['status'], array( 'completed', 'commit' ), true ) && ! $out['needs_dream_half'] && ! $is_parent ) {
                if ( trim( (string) $row['dream_summary_1'] ) === '' ) {
                    $regen = 'dream';
                } elseif ( $row['requirements_json'] === null || ( trim( (string) $row['intro_text'] ) === '' && trim( (string) $row['job_summary_1'] ) === '' ) ) {
                    $regen = 'junk';
                }
            }
            $out['needs_regeneration'] = $regen;

            // Parent's "{Child}'s view": hide the words (and Steve's reply) for withheld items.
            if ( $is_parent ) {
                $hide = $this->withheld_items( $user_id );
                if ( $hide ) {
                    $dr = (array) $out['dream_reasons']; $jr = (array) $out['reasons'];
                    foreach ( $hide as $item ) {
                        if ( array_key_exists( $item, $dr ) ) $dr[ $item ] = self::WITHHELD_TEXT;
                        if ( array_key_exists( $item, $jr ) ) $jr[ $item ] = self::WITHHELD_TEXT;
                        $di = array_search( $item, (array) $out['dream_ranking'], true );
                        if ( $di !== false ) $out['dream_summaries'][ $di ] = '';
                        $ji = array_search( $item, (array) $out['ranking'], true );
                        if ( $ji !== false ) $out['job_summaries'][ $ji ] = '';
                    }
                    $out['dream_reasons'] = (object) $dr;
                    $out['reasons']       = (object) $jr;
                }
                if ( $this->commitment_withheld( $user_id, $row ) ) {
                    $out['commitment_text'] = self::WITHHELD_TEXT;
                    if ( is_array( $out['commitment'] ) ) $out['commitment']['title'] = self::WITHHELD_TEXT;
                }
            }
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

                case 'save_commitment':
                    return $this->save_commitment( $user_id, $row, $req );
            }

            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Invalid step: ' . $step ), 400 );

        } catch ( Exception $e ) {
            error_log( 'Dream & Junk Jobs Submit Error: ' . $e->getMessage() );
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Server error' ), 500 );
        }
    }

    /* ── 5.1 End of dream half: 5 calls ───────────────────────────────── */
    private function generate_dream_analysis( int $user_id, ?array $row, WP_REST_Request $req ) {
        if ( ! $row || empty( $row['dream_items_json'] ) || ! in_array( $row['status'], array( 'dream_reasons', 'dream_done', 'commit', 'completed' ), true ) ) {
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

        $kw_hits = $this->keyword_hits( $user_id, $reasons, 'dream' ); // flags raised here, before any AI call
        $bot     = mfsd_steve_chatbot( self::MAP_SUMMARY );
        if ( ! $bot ) {
            // Answers + any keyword flags are kept; no AI output saved, status unchanged.
            $this->save_fields( $user_id, array( 'dream_ranking_json' => wp_json_encode( $ranking ), 'dream_reasons_json' => wp_json_encode( $reasons ) ) );
            return self::unavailable();
        }

        $name      = $this->display_name( $user_id );
        $age_text  = $this->age_text( $user_id );
        $profile   = $this->profile( $user_id );
        $summaries = array();
        $fit       = array();

        foreach ( $ranking as $i => $job ) {
            $kw  = $kw_hits[ $job ] ?? null;
            $ctx = $this->sg_ctx( 'item', $user_id, array(
                'severity' => $kw ? (string) $kw['severity'] : '', 'matched' => $kw ? (string) $kw['matched'] : '',
                'item' => $job, 'item_text' => $reasons[ $job ], 'field' => 'dream_reason', 'source_ref' => 'djj:dream',
            ) );
            $raw = mfsd_steve_query( $bot, AI_Junk_Jobs_AI::dream_prompt( $name, $age_text, $job, $i + 1, $reasons[ $job ], $profile ), $user_id, 'dream ' . ( $i + 1 ), $ctx );
            if ( $raw === '' ) {
                $summaries[] = "{$job} is a brilliant thing to aim for, {$name}. Keep exploring what the job involves day to day, and talk to anyone you know who does it.";
                continue;
            }
            $parsed      = AI_Junk_Jobs_AI::parse_dream( $raw, (bool) $profile, self::sg_severity( $bot ) );
            $summaries[] = $parsed['text'];
            if ( $parsed['fit'] ) $fit[ $job ] = $parsed['fit'];
        }

        // Regenerating a commit/completed row keeps its status (§4.2).
        $new_status = in_array( $row['status'], array( 'completed', 'commit' ), true ) ? $row['status'] : 'dream_done';
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
            'next'            => ( in_array( $new_status, array( 'completed', 'commit' ), true ) || $this->junk_ready( $row ) ) ? 'generate_analysis' : 'halfway',
        ), 200 );
    }

    /* ── 5.2 + 5.3 End of junk half: intro + 5 junk + conclusion ──────── */
    private function generate_analysis( int $user_id, ?array $row, WP_REST_Request $req ) {
        if ( ! $row || empty( $row['dream_reasons_json'] ) || ! in_array( $row['status'], array( 'reasons', 'dream_done', 'commit', 'completed' ), true ) ) {
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

        $kw_hits = $this->keyword_hits( $user_id, $reasons, 'junk' );
        $bot     = mfsd_steve_chatbot( self::MAP_SUMMARY );
        if ( ! $bot ) {
            $this->save_fields( $user_id, array( 'ranking_json' => wp_json_encode( $ranking ), 'reasons_json' => wp_json_encode( $reasons ) ) );
            return self::unavailable();
        }

        $name          = $this->display_name( $user_id );
        $age_text      = $this->age_text( $user_id );
        $profile       = $this->profile( $user_id );
        $dream_ranking = self::dec( $row['dream_ranking_json'] );
        $dream_reasons = self::dec( $row['dream_reasons_json'] );
        $fit           = $profile ? self::dec( $row['fit_json'] ) : array();
        $dream_flagged = array_column( mfsd_get_concern_flags( $user_id, self::TASK_SLUG, false, true ), 'item_text' ); // incl. moderation

        $dream_lines_short = array();
        foreach ( $dream_ranking as $i => $dj ) {
            $dream_lines_short[] = AI_Junk_Jobs_AI::dream_line( $dj, $i + 1, $fit[ $dj ] ?? null );
        }

        // 1. Intro
        $intro_text = mfsd_steve_query( $bot, AI_Junk_Jobs_AI::intro_prompt( $name, $age_text ), $user_id, 'intro', $this->sg_ctx( 'summary', $user_id ) );
        $intro_text = $intro_text !== '' ? AI_Junk_Jobs_AI::strip_markers( $intro_text )
            : "Steve says: {$name}, what you don't want to do tells us just as much about you as what you do want. Put the two lists side by side and you start to see what really matters to you. Let's dig in.";

        // 2. Junk jobs
        $job_summaries = array();
        $requirements  = array();
        $check         = array();
        $ai_sev        = array();
        foreach ( $ranking as $i => $job ) {
            $kw  = $kw_hits[ $job ] ?? null;
            $ctx = $this->sg_ctx( 'item', $user_id, array(
                'severity' => $kw ? (string) $kw['severity'] : '', 'matched' => $kw ? (string) $kw['matched'] : '',
                'item' => $job, 'item_text' => $reasons[ $job ], 'field' => 'junk_reason', 'source_ref' => 'djj:junk',
            ) );
            $raw = mfsd_steve_query( $bot, AI_Junk_Jobs_AI::junk_prompt( $name, $age_text, $job, $i + 1, $reasons[ $job ], $profile, $dream_lines_short ), $user_id, 'junk ' . ( $i + 1 ), $ctx );
            if ( $raw === '' ) {
                $job_summaries[] = "Your feelings about {$job} make complete sense — and they tell us something important about what you DO need from a career.";
                continue;
            }
            $parsed          = AI_Junk_Jobs_AI::parse_junk( $raw, (bool) $profile, self::sg_severity( $bot ) );
            $job_summaries[] = $parsed['text'];
            if ( $parsed['req'] !== '' )     $requirements[ $job ] = $parsed['req'];
            if ( $parsed['check'] !== '' )   $check[ $job ]        = $parsed['check'];
            if ( $parsed['concern'] !== '' ) $ai_sev[ $job ]       = $parsed['concern'];
        }
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
        $raw       = mfsd_steve_query( $bot, AI_Junk_Jobs_AI::conclusion_prompt( $name, $age_text, $dream_lines, $junk_lines, $profile, $req_names ), $user_id, 'conclusion', $this->sg_ctx( 'summary', $user_id ) );
        $match     = array();
        $actions   = array(); // none parsed → "Write my own" only (§5.3)
        if ( $raw !== '' ) {
            $parsed     = AI_Junk_Jobs_AI::parse_conclusion( $raw, $dream_ranking, $req_names );
            $conclusion = $parsed['text'];
            $match      = $parsed['match'];
            $actions    = $parsed['actions'];
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

        // Stays completed: a saved commitment, or a pre-7.0.0 completer (has requirements_json). A v4 legacy
        // junk-only row (no requirements_json) goes to commit like a new student (§4.2).
        $done   = $row['status'] === 'completed' && ( (string) ( $row['chosen_action'] ?? '' ) !== '' || $row['requirements_json'] !== null );
        $fields = array(
            'ranking_json'      => wp_json_encode( $ranking ),
            'reasons_json'      => wp_json_encode( $reasons ),
            'requirements_json' => wp_json_encode( (object) $requirements ),
            'check_json'        => wp_json_encode( (object) $check ),
            'match_json'        => wp_json_encode( (object) $match ),
            'also_like_json'    => wp_json_encode( $also_like ),
            'mbti_type'         => $profile ? $profile['code'] : null, // server-side only
            'parent_ai_summary' => null,
            // §15: the commitment finishes the task. A row whose commitment is already saved stays completed.
            'status'            => $done ? 'completed' : 'commit',
        );
        // Never change the actions under a child who has already chosen.
        if ( (string) ( $row['chosen_action'] ?? '' ) === '' ) $fields['actions_json'] = wp_json_encode( (object) $actions );
        if ( get_option( 'ai_junk_jobs_save_summary', 1 ) ) {
            $fields['intro_text'] = $intro_text;
            foreach ( $job_summaries as $i => $s ) $fields[ 'job_summary_' . ( $i + 1 ) ] = $s;
            $fields['conclusion'] = $conclusion;
        }
        $this->save_fields( $user_id, $fields );
        // No mfsd_set_task_status( completed ) here any more: save_commitment() finishes the task (§15.2).

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
        $ctx .= 'Answer career questions for a UK student aged ' . ( $age ?: '10-14' ) . '. Relate answers to their dream jobs and needs. ';
        $ctx .= 'Keep answers to 2-4 sentences.';
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

        // §16.4: nothing for a parent who hasn't finished the game.
        if ( $this->parent_gated( $viewer_id, $student_id, $this->get_row( $student_id ) ) ) {
            return new WP_REST_Response( array( 'ok' => true, 'gated' => true, 'html' => '' ), 200 );
        }
        return new WP_REST_Response( array(
            'ok'            => true,
            'html'          => $this->parent_panel_html( $student_id, $viewer_id, true ),
            'has_open_flag' => ! empty( $this->parent_flags( $student_id ) ),
        ), 200 );
    }

    /**
     * Parent tab HTML. With $allow_generate false (page render) only a cached summary is used;
     * otherwise a placeholder lets the JS fetch /parent-summary (which may call SteveGPT).
     */
    private function parent_panel_html( int $student_id, int $viewer_id, bool $allow_generate ): string {
        $row        = $this->get_row( $student_id );
        $child_name = MFSD_Parent_Tabs::child_first_name( $student_id );
        $flags      = $this->parent_flags( $student_id );
        $fallback   = MFSD_Parent_Tabs::concern_fallback_html( $flags, $child_name );
        $lead       = $flags ? MFSD_Parent_Tabs::CONCERN_TITLE : '';

        if ( ! $row || $row['status'] !== 'completed' || $this->is_legacy( $row ) ) {
            $msg = ( $row && $row['status'] === 'commit' )
                ? esc_html( $child_name ) . ' is choosing their action — the last step. Steve\'s parent summary will appear here once they have.'
                : esc_html( $child_name ) . ' hasn\'t finished Dream &amp; Junk Jobs yet. Steve\'s parent summary will appear here once they have.';
            return '<div class="mfsd-pt__parent">' . $fallback . '<p>' . $msg . '</p></div>';
        }

        if ( ! $allow_generate && trim( (string) $row['parent_ai_summary'] ) === '' ) {
            return '<div class="mfsd-pt__loading" id="ai-junk-jobs-parent-root">Steve is preparing your parent summary…</div>';
        }

        $table = $this->table();
        $text  = MFSD_Parent_Tabs::get_or_generate_parent_summary( $table, $student_id, function () use ( $row, $student_id, $viewer_id, $flags ) {
            $bot = mfsd_steve_chatbot( self::MAP_PARENT );
            if ( ! $bot ) return '';
            $raw = mfsd_steve_query( $bot, $this->parent_prompt( $row, $student_id, $flags ), $viewer_id, 'parent summary',
                $this->sg_ctx( 'parent_summary', $student_id, array( 'flags' => $flags ) ) );
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
        foreach ( $this->withheld_items( $student_id ) as $item ) { // never send withheld words to the parent summary
            if ( isset( $dreason[ $item ] ) ) $dreason[ $item ] = self::WITHHELD_TEXT;
            if ( isset( $jreason[ $item ] ) ) $jreason[ $item ] = self::WITHHELD_TEXT;
        }

        $p  = "This is for the PARENT or carer of {$child}" . ( $age ? ", aged {$age}" : '' ) . ". "; // 7.0.1: persona lives in the chatbot instructions
        $p .= "Write in clear, warm adult language (not student language), UK English, about 300 words. ";
        $p .= "Don't assume anything about family structure, money or circumstances.\n\n";
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

        $kinds  = array( 'skill' => 'a skill to practise', 'explore' => 'something to explore', 'habit' => 'a habit to build', 'own' => 'their own idea' );
        $action = (string) ( $row['commitment_text'] ?? '' );
        if ( $action !== '' && $this->commitment_withheld( $student_id, $row ) ) $action = self::WITHHELD_TEXT;
        if ( $action !== '' ) {
            $p .= "\nTHEIR COMMITMENT (in their own words): \"{$action}\" — " . ( $kinds[ $row['chosen_action'] ?? '' ] ?? 'an action' ) . ".\n";
        }

        // Flags are given to SteveGPT 14 through the safeguarding context (kind parent_summary), which adds
        // the "Something to be aware of" rules; unreleased moderation items never reach this prompt.
        $p .= "\nUse these section markers, in this order:\n";
        if ( $flags ) $p .= "[SECTION:Something to be aware of] (ALWAYS FIRST — follow the safeguarding instructions below)\n";
        $p .= "[SECTION:What their choices tell you]\n";
        $p .= $profile ? "[SECTION:How their personality fits] — use the character name and fit bands, never a code\n" : '';
        $p .= "[SECTION:Conversation starters] — 3 open questions\n";
        if ( $action !== '' ) {
            $p .= "[SECTION:Their commitment] — the action in {$child}'s own words and which kind it is; in plain adult language why this small step matters "
                . "(link it to their #1 dream job and the requirements they worked out); 1-2 practical, low-cost ways to help it happen; end with: "
                . "\"When you see it happen, confirm it on your home screen. That's when {$child} earns their coins.\"\n";
        }
        $p .= "[SECTION:How you can help] — work experience, people to talk to, taster days; all low-cost\n";
        $p .= "Sign off \"- Steve\". Then on a final line output PARENT_NOTE: followed by one line for the parent about any concern, or PARENT_NOTE: none";
        return $p;
    }

    /* ================================================================
       15. COMMITMENT (required) — save_commitment finishes the task
       ================================================================ */
    private function save_commitment( int $user_id, ?array $row, WP_REST_Request $req ) {
        if ( ! $row || $this->is_legacy( $row ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Please finish your jobs first' ), 400 );
        }
        // Idempotent: a saved commitment is never replaced.
        if ( $row['status'] === 'completed' && (string) $row['chosen_action'] !== '' ) {
            return new WP_REST_Response( $this->status_payload( $row, $user_id, false ), 200 );
        }
        // From commit, or a pre-7.0.0 completer who never had the step (Decision 25).
        if ( ! in_array( $row['status'], array( 'commit', 'completed' ), true ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Please finish your jobs first' ), 400 );
        }

        $kind    = sanitize_key( (string) $req->get_param( 'kind' ) );
        $actions = self::dec( $row['actions_json'] ?? '' );
        if ( ! in_array( $kind, self::ACTION_KINDS, true ) || ( $kind !== 'own' && empty( $actions[ $kind ] ) ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Please pick an action' ), 400 );
        }
        $raw  = trim( sanitize_text_field( (string) $req->get_param( 'text' ) ) );
        if ( $raw === '' ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'Please write your action' ), 400 );
        if ( mb_strlen( $raw ) > 140 ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'Please keep your action to 140 characters' ), 400 );
        $steve = $kind === 'own' ? '' : (string) $actions[ $kind ];

        $card_id = null;
        if ( function_exists( 'mfsd_create_commitment' ) ) {
            // mfsd-commitments runs the safeguarding check on the card text (Decision 17).
            $res = mfsd_create_commitment( $user_id, array(
                'type'        => 'action',
                'source_task' => self::TASK_SLUG,
                'source_ref'  => 'djj:' . $kind,
                'title'       => $raw,
                'detail'      => ( $steve !== '' && $steve !== $raw ) ? 'Steve suggested: ' . $steve : '',
            ) );
            if ( is_wp_error( $res ) ) {
                return new WP_REST_Response( array( 'ok' => false, 'error' => $res->get_error_message() ), 400 );
            }
            $card_id = (int) $res;
        } else {
            mfsd_safeguarding_check( $user_id, self::TASK_SLUG, 'Commitment', $raw, array( 'field' => 'commitment', 'source_ref' => 'djj:commitment' ) );
        }

        $fields = array(
            'chosen_action'   => $kind,
            'commitment_id'   => $card_id,
            'commitment_text' => $raw,
            'status'          => 'completed',
            'completed_at'    => current_time( 'mysql' ),
            'parent_ai_summary' => null,
        );
        if ( ! self::dec( $row['decoys_json'] ?? '' ) ) $fields['decoys_json'] = wp_json_encode( $this->pick_decoys( $row ) );
        $this->save_fields( $user_id, $fields );

        if ( function_exists( 'mfsd_set_task_status' ) && get_option( 'ai_junk_jobs_course_management', 1 ) ) {
            mfsd_set_task_status( $user_id, self::TASK_SLUG, 'completed' ); // Quest Log then awards badge_junk_jobs
        }
        $this->award_dream_badge( $user_id );

        $row     = $this->get_row( $user_id );
        $payload = $this->status_payload( $row, $user_id, false );
        $payload['chat_context'] = get_option( self::MAP_CHAT, '' ) ? $this->chat_context( $row, $user_id ) : '';
        return new WP_REST_Response( $payload, 200 );
    }

    /** badge_dream_jobs + its coins, once (§15.4). Quest Log 3.0.0 has no revoke, so a reset keeps it. */
    private function award_dream_badge( int $user_id ): void {
        if ( ! class_exists( 'MFSD_Quest_Log_DB' ) || ! class_exists( 'MFSD_Quest_Log_Wallet' ) ) return;
        $coins  = max( 0, (int) get_option( 'ai_junk_jobs_badge_coins', 10 ) );
        $db     = new MFSD_Quest_Log_DB();
        $wallet = new MFSD_Quest_Log_Wallet();
        if ( $db->award_badge( $user_id, 'badge_dream_jobs', $coins, array( 'source' => self::TASK_SLUG ) )
             && $coins > 0 && ! $wallet->has_source( $user_id, 'badge_dream_jobs' ) ) {
            $wallet->earn( $user_id, 'badge_dream_jobs', $coins, 'Dream Jobs badge earned' );
        }
    }

    /** The live card for the child's results ("Your action: … · On your home screen"). */
    private function commitment_card( array $row ): ?array {
        $id = (int) ( $row['commitment_id'] ?? 0 );
        if ( ! $id || ! function_exists( 'mfsd_get_commitments' ) ) return null;
        foreach ( mfsd_get_commitments( (int) $row['user_id'], array( 'source_task' => self::TASK_SLUG ) ) as $c ) {
            if ( (int) $c['id'] === $id ) return array( 'id' => $id, 'status' => (string) $c['status'], 'title' => (string) $c['title'] );
        }
        return null;
    }

    /* ================================================================
       16. PARENT GUESSING GAME
       ================================================================ */
    private function guess_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::GUESS_TABLE;
    }

    private function guess_table_exists(): bool {
        global $wpdb;
        $t = $this->guess_table();
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
    }

    private function get_guess( int $parent_id, int $child_id ): ?array {
        if ( ! $parent_id || ! $child_id || ! $this->guess_table_exists() ) return null;
        global $wpdb;
        $g = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->guess_table()} WHERE parent_id = %d AND child_id = %d LIMIT 1", $parent_id, $child_id ), ARRAY_A );
        return $g ?: null;
    }

    private function parent_has_played( int $parent_id, int $child_id ): bool {
        $g = $this->get_guess( $parent_id, $child_id );
        return $g && ! empty( $g['completed_at'] );
    }

    /** True while the child is completed and this parent hasn't finished the game (§7). */
    private function parent_gated( int $parent_id, int $child_id, ?array $row ): bool {
        return $row && $row['status'] === 'completed' && ! $this->is_legacy( $row ) && ! $this->parent_has_played( $parent_id, $child_id );
    }

    private function decoy_list(): array {
        $raw  = (string) get_option( 'ai_junk_jobs_decoys', self::DEFAULT_DECOYS );
        $list = array();
        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $l ) {
            $l = trim( sanitize_text_field( $l ) );
            if ( $l !== '' && ! in_array( strtolower( $l ), array_map( 'strtolower', $list ), true ) ) $list[] = mb_substr( $l, 0, 80 );
        }
        return $list;
    }

    /** Up to DECK_SIZE decoys not matching any of the child's 10 (case-insensitive), random order (§16.1). */
    private function pick_decoys( array $row ): array {
        $taken = array_map( 'strtolower', array_merge( self::dec( $row['dream_ranking_json'] ?? '' ), self::dec( $row['ranking_json'] ?? '' ) ) );
        $pool  = array_values( array_filter( $this->decoy_list(), function ( $d ) use ( $taken ) {
            return ! in_array( strtolower( $d ), $taken, true );
        } ) );
        shuffle( $pool );
        return array_slice( $pool, 0, self::DECK_SIZE );
    }

    /** job => ['truth' => love|hate|neither, 'list' => dream|junk|decoy, 'rank' => n] */
    private function truth_map( array $row, array $deck ): array {
        $dream = self::dec( $row['dream_ranking_json'] );
        $junk  = self::dec( $row['ranking_json'] );
        $out   = array();
        foreach ( $deck as $job ) {
            $d = array_search( strtolower( $job ), array_map( 'strtolower', $dream ), true );
            $j = array_search( strtolower( $job ), array_map( 'strtolower', $junk ), true );
            if ( $d !== false )     $out[ $job ] = array( 'truth' => 'love', 'list' => 'dream', 'rank' => $d + 1 );
            elseif ( $j !== false ) $out[ $job ] = array( 'truth' => 'hate', 'list' => 'junk', 'rank' => $j + 1 );
            else                    $out[ $job ] = array( 'truth' => 'neither', 'list' => 'decoy', 'rank' => 0 );
        }
        return $out;
    }

    /** Linked parent + completed child, or a WP_Error. */
    private function guess_context( WP_REST_Request $req ) {
        $parent_id = $this->get_current_user_id();
        $child_id  = absint( $req->get_param( 'student_id' ) );
        if ( ! $child_id || $child_id === $parent_id ) return new WP_Error( 'forbidden', 'Parents only', array( 'status' => 403 ) );
        $linked = MFSD_Parent_Tabs::assert_linked( $parent_id, $child_id );
        if ( is_wp_error( $linked ) ) return $linked;
        $row = $this->get_row( $child_id );
        if ( ! $row || $row['status'] !== 'completed' || $this->is_legacy( $row ) ) {
            return new WP_Error( 'not_ready', 'Not finished yet', array( 'status' => 409 ) );
        }
        if ( ! $this->guess_table_exists() ) $this->create_guess_table();
        return array( $parent_id, $child_id, $row );
    }

    /** The parent's game, creating the shuffled deck on first open (§16.1). */
    private function ensure_guess( int $parent_id, int $child_id, array $row ): array {
        $g = $this->get_guess( $parent_id, $child_id );
        if ( $g ) return $g;

        global $wpdb;
        $flagged = $this->open_flag_items( $child_id );
        $kept    = array();
        foreach ( array_merge( self::dec( $row['dream_ranking_json'] ), self::dec( $row['ranking_json'] ) ) as $job ) {
            if ( ! in_array( strtolower( trim( $job ) ), $flagged, true ) ) $kept[] = $job;
        }
        $decoys = self::dec( $row['decoys_json'] ?? '' );
        $need   = max( 0, self::DECK_SIZE - count( $kept ) );
        if ( count( $decoys ) < $need ) { // pre-7.0.0 completer, or an admin list edited since
            $decoys = array_values( array_unique( array_merge( $decoys, $this->pick_decoys( $row ) ) ) );
            $this->save_fields( $child_id, array( 'decoys_json' => wp_json_encode( $decoys ) ) );
        }
        $deck = array_merge( $kept, array_slice( $decoys, 0, $need ) );
        shuffle( $deck );
        $wpdb->insert( $this->guess_table(), array(
            'parent_id'    => $parent_id,
            'child_id'     => $child_id,
            'deck_json'    => wp_json_encode( array_values( $deck ) ),
            'answers_json' => '{}',
            'created_at'   => current_time( 'mysql' ),
        ) );
        return $this->get_guess( $parent_id, $child_id ) ?: array( 'deck_json' => wp_json_encode( $deck ), 'answers_json' => '{}', 'completed_at' => null );
    }

    private function guess_response( array $g, array $row, int $child_id ): array {
        $deck = self::dec( $g['deck_json'] );
        $out  = array(
            'ok'         => true,
            'child_name' => MFSD_Parent_Tabs::child_first_name( $child_id ),
            'deck'       => $deck,
            'answers'    => (object) self::dec( $g['answers_json'] ),
            'done'       => ! empty( $g['completed_at'] ),
        );
        if ( $out['done'] ) { // the truth is only ever sent after finishing
            $truth   = $this->truth_map( $row, $deck );
            $answers = self::dec( $g['answers_json'] );
            $cards   = array();
            foreach ( $deck as $job ) {
                $cards[] = array( 'job' => $job, 'answer' => (string) ( $answers[ $job ] ?? '' ) ) + $truth[ $job ];
            }
            $out += array( 'cards' => $cards, 'score' => (int) $g['score'], 'total' => count( $deck ), 'coins' => (int) $g['coins_awarded'] );
        }
        return $out;
    }

    public function handle_guess_get( WP_REST_Request $req ) {
        $c = $this->guess_context( $req );
        if ( is_wp_error( $c ) ) return $c;
        list( $parent_id, $child_id, $row ) = $c;
        return new WP_REST_Response( $this->guess_response( $this->ensure_guess( $parent_id, $child_id, $row ), $row, $child_id ), 200 );
    }

    public function handle_guess_answer( WP_REST_Request $req ) {
        $c = $this->guess_context( $req );
        if ( is_wp_error( $c ) ) return $c;
        list( $parent_id, $child_id, $row ) = $c;
        $g = $this->ensure_guess( $parent_id, $child_id, $row );
        if ( ! empty( $g['completed_at'] ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'Already finished' ), 409 );

        $job    = sanitize_text_field( (string) $req->get_param( 'job' ) );
        $answer = sanitize_key( (string) $req->get_param( 'answer' ) );
        $deck   = self::dec( $g['deck_json'] );
        if ( ! in_array( $job, $deck, true ) || ! in_array( $answer, array( 'love', 'hate', 'neither', '' ), true ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Invalid answer' ), 400 );
        }
        // Two quick taps send two requests; read-modify-write under a lock so one answer can't overwrite the other.
        global $wpdb;
        $lock = 'mfsd_djj_guess_' . (int) $g['id'];
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Busy, please try again' ), 409 );
        }
        $g = $this->get_guess( $parent_id, $child_id ) ?: $g; // fresh copy inside the lock
        if ( ! empty( $g['completed_at'] ) ) {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Already finished' ), 409 );
        }
        $answers = self::dec( $g['answers_json'] );
        if ( $answer === '' ) unset( $answers[ $job ] ); else $answers[ $job ] = $answer; // '' = undo
        $wpdb->update( $this->guess_table(), array( 'answers_json' => wp_json_encode( (object) $answers ) ), array( 'id' => (int) $g['id'] ) );
        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        return new WP_REST_Response( array( 'ok' => true, 'answered' => count( $answers ), 'total' => count( $deck ) ), 200 );
    }

    public function handle_guess_finish( WP_REST_Request $req ) {
        $c = $this->guess_context( $req );
        if ( is_wp_error( $c ) ) return $c;
        list( $parent_id, $child_id, $row ) = $c;
        $g = $this->ensure_guess( $parent_id, $child_id, $row );

        if ( empty( $g['completed_at'] ) ) {
            $deck    = self::dec( $g['deck_json'] );
            $answers = self::dec( $g['answers_json'] );
            foreach ( $deck as $job ) {
                if ( empty( $answers[ $job ] ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'Answer every card first' ), 400 );
            }
            $score = 0;
            foreach ( $this->truth_map( $row, $deck ) as $job => $t ) {
                if ( $answers[ $job ] === $t['truth'] ) $score++;
            }
            global $wpdb;
            // Claim the row first: only the request whose UPDATE changes it (completed_at was NULL) pays.
            // A double submit gets 0 rows and pays nothing (review: guess coins race).
            $claimed = $wpdb->query( $wpdb->prepare(
                "UPDATE {$this->guess_table()} SET score = %d, coins_awarded = 0, completed_at = %s WHERE id = %d AND completed_at IS NULL",
                $score, current_time( 'mysql' ), (int) $g['id']
            ) );
            $coins = $score * max( 0, (int) get_option( 'ai_junk_jobs_guess_coins', 2 ) );
            if ( $claimed === 1 && $coins > 0 && class_exists( 'MFSD_Quest_Log_Wallet' ) ) {
                $wallet = new MFSD_Quest_Log_Wallet();
                $source = 'djj_guess_' . $parent_id;
                if ( ! $wallet->has_source( $child_id, $source ) ) {
                    $wallet->earn( $child_id, $source, $coins, sprintf( '%s guessed %d of %d of your jobs', $this->first_name( $parent_id ), $score, count( $deck ) ) );
                    $wpdb->update( $this->guess_table(), array( 'coins_awarded' => $coins ), array( 'id' => (int) $g['id'] ) );
                }
            }
            $g = $this->get_guess( $parent_id, $child_id );
        }
        return new WP_REST_Response( $this->guess_response( $g, $row, $child_id ), 200 );
    }

    private function first_name( int $user_id ): string {
        $f = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
        if ( $f !== '' ) return $f;
        $u = get_userdata( $user_id );
        return $u ? (string) $u->display_name : 'A parent';
    }

    /** Child's results: one line per parent who has played (§3, item 8). */
    private function guess_lines( int $child_id ): array {
        if ( ! $this->guess_table_exists() ) return array();
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT parent_id, score, coins_awarded, deck_json FROM {$this->guess_table()} WHERE child_id = %d AND completed_at IS NOT NULL ORDER BY completed_at ASC",
            $child_id
        ), ARRAY_A ) ?: array();
        $out = array();
        foreach ( $rows as $r ) {
            $out[] = array( 'name' => $this->first_name( (int) $r['parent_id'] ), 'score' => (int) $r['score'],
                            'total' => count( self::dec( $r['deck_json'] ) ), 'coins' => (int) $r['coins_awarded'] );
        }
        return $out;
    }

    /* ================================================================
       4.4 GETTER — completed rows only; no codes, AI text or flags
       ================================================================ */
    public function dream_junk_jobs( int $user_id ): ?array {
        $row = $this->get_row( $user_id );
        if ( ! $row || $row['status'] !== 'completed' || $this->is_legacy( $row ) ) return null;

        $personal = $this->personality_state( $user_id ) === 'ok';
        $fit      = array();
        if ( $personal ) {
            foreach ( self::dec( $row['fit_json'] ) as $job => $f ) {
                if ( ! empty( $f['fit'] ) ) $fit[ $job ] = (string) $f['fit'];
            }
        }
        $twists = array();
        if ( $personal ) {
            foreach ( self::dec( $row['check_json'] ) as $job => $c ) if ( $c === 'twist' ) $twists[] = (string) $job;
        }
        $dr = self::dec( $row['dream_reasons_json'] );
        $jr = self::dec( $row['reasons_json'] );
        $ct = (string) $row['commitment_text'];
        foreach ( $this->withheld_items( $user_id ) as $item ) { // Decision 15
            if ( isset( $dr[ $item ] ) ) $dr[ $item ] = self::WITHHELD_TEXT;
            if ( isset( $jr[ $item ] ) ) $jr[ $item ] = self::WITHHELD_TEXT;
        }
        if ( $ct !== '' && $this->commitment_withheld( $user_id, $row ) ) $ct = self::WITHHELD_TEXT;
        $reqs = array();
        foreach ( self::dec( $row['ranking_json'] ) as $job ) {
            $r = self::dec( $row['requirements_json'] )[ $job ] ?? '';
            if ( $r !== '' && ! in_array( $r, $reqs, true ) ) $reqs[] = $r;
        }
        $actions = self::dec( $row['actions_json'] ?? '' );
        return array(
            'status'          => 'completed',
            'dream_ranked'    => self::dec( $row['dream_ranking_json'] ),
            'dream_reasons'   => $dr,
            'fit'             => $fit,
            'twists'          => $twists,
            'requirements'    => $reqs,
            'junk_ranked'     => self::dec( $row['ranking_json'] ),
            'junk_reasons'    => $jr,
            'actions'         => $actions ?: null,
            'chosen_action'   => (string) $row['chosen_action'] !== '' ? (string) $row['chosen_action'] : null,
            'commitment_text' => $ct,
            'completed_at'    => (string) ( ( $row['completed_at'] ?? '' ) ?: $row['updated_at'] ), // updated_at only for rows finished before 7.0.0
        );
    }

    private function halfway_video(): ?array {
        if ( ! get_option( 'ai_junk_jobs_halfway_video_on', 0 ) ) return null;
        $id  = (int) get_option( 'ai_junk_jobs_halfway_video', 0 );
        $url = $id ? wp_get_attachment_url( $id ) : '';
        if ( ! $url ) return null;
        $pid    = (int) get_option( 'ai_junk_jobs_halfway_poster', 0 );
        $poster = $pid ? wp_get_attachment_url( $pid ) : '';
        return array( 'url' => esc_url_raw( $url ), 'poster' => $poster ? esc_url_raw( $poster ) : '' );
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
        if ( $s->status === 'commit' ) return 'Choosing action';
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
            // 7.0.0
            update_option( 'ai_junk_jobs_halfway_video_on', isset( $_POST['halfway_video_on'] ) ? 1 : 0 );
            update_option( 'ai_junk_jobs_halfway_video',    absint( $_POST['halfway_video'] ?? 0 ) );
            update_option( 'ai_junk_jobs_halfway_poster',   absint( $_POST['halfway_poster'] ?? 0 ) );
            update_option( 'ai_junk_jobs_decoys',           sanitize_textarea_field( wp_unslash( $_POST['decoys'] ?? '' ) ) );
            update_option( 'ai_junk_jobs_guess_coins',      max( 0, min( 50, intval( $_POST['guess_coins'] ?? 2 ) ) ) );
            update_option( 'ai_junk_jobs_badge_coins',      max( 0, min( 500, intval( $_POST['badge_coins'] ?? 10 ) ) ) );
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        if ( isset( $_POST['action'] ) && $_POST['action'] === 'reset_jj_student'
             && check_admin_referer( 'ai_junk_jobs_reset_student' ) ) {
            $reset_uid = intval( $_POST['reset_user_id'] ?? 0 );
            if ( $reset_uid > 0 ) {
                $old = $this->get_row( $reset_uid );
                // An open card from this task is dropped; a validated card stays as history (§8).
                if ( $old && (int) $old['commitment_id'] && function_exists( 'mfsd_update_commitment' ) ) {
                    $cid = (int) $old['commitment_id'];
                    $res = mfsd_update_commitment( $cid, array( 'status' => 'dropped' ), $reset_uid );
                    // A checked-in card can't go straight to dropped: back to not_yet first, then drop.
                    if ( is_wp_error( $res ) && $res->get_error_code() === 'invalid_transition' ) {
                        if ( mfsd_update_commitment( $cid, array( 'status' => 'not_yet' ), $reset_uid ) === true ) {
                            mfsd_update_commitment( $cid, array( 'status' => 'dropped' ), $reset_uid );
                        }
                    } // any other WP_Error (validated / already closed) is fine: it stays as history
                }
                // Guesses go, and each parent's guess coins are reversed (badge_dream_jobs stays: no revoke helper).
                if ( $this->guess_table_exists() ) {
                    $parents = $wpdb->get_col( $wpdb->prepare( "SELECT parent_id FROM {$this->guess_table()} WHERE child_id = %d", $reset_uid ) );
                    if ( class_exists( 'MFSD_Quest_Log_Wallet' ) ) {
                        $wallet = new MFSD_Quest_Log_Wallet();
                        foreach ( $parents as $pid ) $wallet->reverse_source( $reset_uid, 'djj_guess_' . (int) $pid, 'Dream & Junk Jobs reset' );
                    }
                    $wpdb->delete( $this->guess_table(), array( 'child_id' => $reset_uid ) );
                }
                // Clears both halves (one row). Concern flags are kept.
                $wpdb->delete( $table, array( 'user_id' => $reset_uid ) );
                if ( function_exists( 'mfsd_get_task_order_row' ) ) {
                    $wpdb->delete( $wpdb->prefix . 'mfsd_task_progress',
                        array( 'student_id' => $reset_uid, 'task_slug' => self::TASK_SLUG ) );
                }
                $u    = get_user_by( 'id', $reset_uid );
                $name = $u ? $u->display_name : 'Student';
                echo '<div class="notice notice-success"><p>' . esc_html( $name ) . '\'s Dream &amp; Junk Jobs data (both halves) and progress have been reset. Guesses cleared and their coins reversed; any open card dropped. Concern flags and the Dream Jobs badge were kept.</p></div>';
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
        $missing      = mfsd_steve_missing_mappings( array( self::MAP_SUMMARY, self::MAP_PARENT, self::MAP_CHAT ) );
        $students     = $wpdb->get_results(
            "SELECT j.user_id, j.status, j.intro_text, j.analysis, j.dream_items_json, j.dream_summary_1, j.parent_ai_summary, j.chosen_action, j.commitment_text, u.display_name
             FROM $table j JOIN {$wpdb->users} u ON u.ID = j.user_id
             ORDER BY u.display_name ASC"
        );
        $guesses = array();
        if ( $this->guess_table_exists() ) {
            foreach ( $wpdb->get_results( "SELECT child_id, parent_id, score, coins_awarded, deck_json, completed_at FROM {$this->guess_table()}", ARRAY_A ) as $g ) {
                $guesses[ (int) $g['child_id'] ][] = $this->first_name( (int) $g['parent_id'] ) . ' · '
                    . ( $g['completed_at'] ? (int) $g['score'] . '/' . count( self::dec( $g['deck_json'] ) ) . ' · ' . (int) $g['coins_awarded'] . ' coins' : 'playing' );
            }
        }
        $video_on = get_option( 'ai_junk_jobs_halfway_video_on', 0 );
        $video_id = (int) get_option( 'ai_junk_jobs_halfway_video', 0 );
        $poster_id = (int) get_option( 'ai_junk_jobs_halfway_poster', 0 );
        ?>
        <div class="wrap">
            <h1>⚒️ Dream &amp; Junk Jobs Settings</h1>

            <?php if ( ! function_exists( 'mfsd_get_task_status' ) ): ?>
                <div class="notice notice-warning">
                    <p><strong>MFSD Ordering Utility</strong> is not active — course management features will not function until it is activated.</p>
                </div>
            <?php endif; ?>
            <?php if ( ! function_exists( 'mfsd_safeguarding_check' ) ): ?>
                <div class="notice notice-error"><p><strong>MFSD Safeguarding is not active.</strong> Students can't submit answers until it is.</p></div>
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
                        <th>Safeguarding words</th>
                        <td>
                            <p class="description">Steve's replies are safeguarded by SteveGPT 14 through MFSD Safeguarding's prompt blocks. Urgent, moderation and concern words are managed centrally in <a href="<?php echo esc_url( admin_url( 'admin.php?page=mfsd-sg-words' ) ); ?>">MFSD Safeguarding → Word list</a> (filter by "Dream &amp; Junk Jobs" to see what this task checks).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Halfway video</th>
                        <td>
                            <label><input type="checkbox" name="halfway_video_on" value="1" <?php checked( $video_on, 1 ); ?>> <strong>Show halfway video</strong></label>
                            <p>
                                <input type="number" min="0" name="halfway_video" id="djj-video" value="<?php echo esc_attr( $video_id ); ?>" style="width:110px">
                                <button type="button" class="button djj-media" data-target="djj-video" data-type="video">Choose video</button>
                                <span class="description"><?php echo $video_id ? esc_html( (string) wp_get_attachment_url( $video_id ) ) : 'Media Library attachment ID'; ?></span>
                            </p>
                            <p>
                                <input type="number" min="0" name="halfway_poster" id="djj-poster" value="<?php echo esc_attr( $poster_id ); ?>" style="width:110px">
                                <button type="button" class="button djj-media" data-target="djj-poster" data-type="image">Choose poster (optional)</button>
                            </p>
                            <p class="description">Use the Media Library (the deployer wipes plugin folders). The "Let's go" button is always shown, so the video never blocks a student.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="djj-decoys">Decoy jobs</label></th>
                        <td>
                            <textarea name="decoys" id="djj-decoys" rows="8" cols="40"><?php echo esc_textarea( (string) get_option( 'ai_junk_jobs_decoys', self::DEFAULT_DECOYS ) ); ?></textarea>
                            <p class="description">One per line. The parent guessing game adds 4 that don't match any of the child's jobs (more if a flagged job is left out).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="djj-gc">Coins per correct guess</label></th>
                        <td><input type="number" min="0" max="50" name="guess_coins" id="djj-gc" value="<?php echo esc_attr( (int) get_option( 'ai_junk_jobs_guess_coins', 2 ) ); ?>" style="width:80px"> <span class="description">Paid to the child once per parent.</span></td>
                    </tr>
                    <tr>
                        <th><label for="djj-bc">Dream Jobs badge coins</label></th>
                        <td><input type="number" min="0" max="500" name="badge_coins" id="djj-bc" value="<?php echo esc_attr( (int) get_option( 'ai_junk_jobs_badge_coins', 10 ) ); ?>" style="width:80px"> <span class="description">Awarded with <code>badge_dream_jobs</code> when the commitment is saved.</span></td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" class="button button-primary" value="Save Settings"></p>
                <script>
                document.querySelectorAll('.djj-media').forEach(function (b) {
                    b.addEventListener('click', function () {
                        if (!window.wp || !wp.media) return;
                        var f = wp.media({ title: b.textContent, library: { type: b.dataset.type }, multiple: false });
                        f.on('select', function () { document.getElementById(b.dataset.target).value = f.state().get('selection').first().id; });
                        f.open();
                    });
                });
                </script>
            </form>

            <hr>
            <h2>Student Records</h2>
            <?php if ( empty( $students ) ): ?>
                <p style="color:#999;">No students have started Dream &amp; Junk Jobs yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Student</th><th>Half</th><th>Status</th><th>Action</th><th>Guesses</th><th>Summary Saved</th><th>Parent Summary</th><th>Ordering Status</th></tr></thead>
                    <tbody>
                        <?php foreach ( $students as $s ):
                            $order_status = function_exists( 'mfsd_get_task_status' ) ? mfsd_get_task_status( $s->user_id, self::TASK_SLUG ) : '—';
                            $has_summary  = ! empty( $s->intro_text ) || ! empty( $s->dream_summary_1 ) || ! empty( $s->analysis );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $s->display_name ); ?></strong></td>
                            <td><?php echo esc_html( $this->half_label( $s ) ); ?></td>
                            <td><?php echo esc_html( $s->status ); ?></td>
                            <td><?php echo $s->chosen_action ? esc_html( $s->chosen_action . ': ' . $s->commitment_text ) : '—'; ?></td>
                            <td><?php echo ! empty( $guesses[ (int) $s->user_id ] ) ? esc_html( implode( '; ', $guesses[ (int) $s->user_id ] ) ) : '—'; ?></td>
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
            <form method="post" action="" onsubmit="return confirm('Permanently delete all Dream & Junk Jobs data (both halves) and parent guesses for this student, and reverse the guess coins? Concern flags are kept.');">
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

if ( ! function_exists( 'mfsd_get_dream_junk_jobs' ) ) {
    /**
     * Read-only Dream & Junk Jobs results for other plugins (spec §4.4).
     * @return array|null null unless the task is completed.
     */
    function mfsd_get_dream_junk_jobs( int $user_id ): ?array {
        return AI_Junk_Jobs::$inst ? AI_Junk_Jobs::$inst->dream_junk_jobs( $user_id ) : null;
    }
}
