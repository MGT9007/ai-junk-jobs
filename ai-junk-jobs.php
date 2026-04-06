<?php
/**
 * Plugin Name: AI Junk Jobs
 * Description: Students explore jobs they DON'T want, rank them, explain why, and get AI-powered insights to reframe into positive requirements. Use shortcode [ai_junk_jobs].
 * Version: 4.0.0
 * Author: MisterT9007
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AI_Junk_Jobs {
    const VERSION      = '4.0.0';
    const TABLE        = 'mfsd_ai_junk_jobs_results';
    const NONCE_ACTION = 'wp_rest';

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        add_action( 'init', array( $this, 'register_assets' ) );
        add_shortcode( 'ai_junk_jobs', array( $this, 'shortcode' ) );
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'plugins_loaded', array( $this, 'check_version' ) );
    }

    public function check_version() {
        $saved_version = get_option( 'ai_junk_jobs_version' );
        if ( $saved_version !== self::VERSION ) {
            $this->maybe_upgrade_table();
            flush_rewrite_rules();
            update_option( 'ai_junk_jobs_version', self::VERSION );
            error_log( 'AI Junk Jobs: Upgraded to version ' . self::VERSION );
        }
    }

    /* ── Table upgrade — adds new columns to existing installs ── */
    private function maybe_upgrade_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $columns = $wpdb->get_col( "DESCRIBE $table", 0 );

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
    }

    public function on_activate() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id       BIGINT UNSIGNED NOT NULL,
            jobs_json     LONGTEXT NULL,
            ranking_json  LONGTEXT NULL,
            reasons_json  LONGTEXT NULL,
            analysis      LONGTEXT NULL,
            intro_text    LONGTEXT NULL,
            job_summary_1 LONGTEXT NULL,
            job_summary_2 LONGTEXT NULL,
            job_summary_3 LONGTEXT NULL,
            job_summary_4 LONGTEXT NULL,
            job_summary_5 LONGTEXT NULL,
            conclusion    LONGTEXT NULL,
            mbti_type     CHAR(4) NULL,
            status        VARCHAR(20) DEFAULT 'not_started',
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user (user_id),
            KEY idx_status (status),
            UNIQUE KEY idx_user_unique (user_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
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

    public function shortcode( $atts, $content = null ) {
        $handle = 'ai-junk-jobs';

        // ── Ordering gate ──────────────────────────────────────────────────
        if ( function_exists( 'mfsd_get_task_status' ) && get_option( 'ai_junk_jobs_course_management', 1 ) ) {
            $student_id = get_current_user_id();
            $status     = mfsd_get_task_status( $student_id, 'junk_jobs' );
            if ( $status === 'locked' ) {
                if ( function_exists( 'mfsd_ordering_locked_message' ) ) {
                    return mfsd_ordering_locked_message( 'junk_jobs' );
                }
                return '<p style="text-align:center;padding:40px;color:#555;">This activity is not available yet. Please complete the previous activity first.</p>';
            }
            if ( $status === 'available' ) {
                mfsd_set_task_status( $student_id, 'junk_jobs', 'in_progress' );
            }
        }

        wp_enqueue_script( $handle );
        wp_enqueue_style( $handle );

        $user_id = $this->get_current_user_id();

        // Age-based minimum word count
        $dob       = get_user_meta( $user_id, 'date_of_birth', true );
        $min_words = 30;
        if ( $dob ) {
            $age = date_diff( date_create( $dob ), date_create( 'today' ) )->y;
            if ( $age >= 14 )      $min_words = 100;
            elseif ( $age >= 13 )  $min_words = 50;
            else                   $min_words = 30;
        }

        $config = array(
            'restUrlSubmit' => esc_url_raw( rest_url( 'ai-junk-jobs/v1/submit' ) ),
            'restUrlStatus' => esc_url_raw( rest_url( 'ai-junk-jobs/v1/status' ) ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'user'          => is_user_logged_in() ? wp_get_current_user()->user_login : '',
            'email'         => is_user_logged_in() ? wp_get_current_user()->user_email : '',
            'userId'        => $user_id,
            'minWords'      => $min_words,
            'urlBadges'     => 'https://mfsd.me/badges/',
            'urlCourse'     => 'https://mfsd.me/about/parent-portal-home/?course_id=1&student_id=' . $user_id,
        );

        wp_add_inline_script(
            $handle,
            'window.AI_JUNK_JOBS_CFG = ' . wp_json_encode( $config ) . ';',
            'before'
        );

        return '<div id="ai-junk-jobs-root"></div>';
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
    }

    public function check_permission( WP_REST_Request $request ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'unauthorized', 'You must be logged in', array( 'status' => 401 ) );
        }
        return true;
    }

    /* ── Status ── */
    public function handle_status( WP_REST_Request $req ) {
        global $wpdb;
        $user_id = $this->get_current_user_id();

        if ( ! $user_id ) {
            return new WP_REST_Response( array( 'ok' => true, 'status' => 'not_started' ), 200 );
        }

        $table = $wpdb->prefix . self::TABLE;
        $saved = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d LIMIT 1", $user_id
        ), ARRAY_A );

        if ( ! $saved ) {
            return new WP_REST_Response( array( 'ok' => true, 'status' => 'not_started' ), 200 );
        }

        $jobs    = json_decode( $saved['jobs_json'],    true );
        $ranking = json_decode( $saved['ranking_json'], true );
        $reasons = json_decode( $saved['reasons_json'], true );
        $status  = $saved['status'];

        if ( $status === 'completed' ) {
            // Build job summaries array
            $job_summaries = array();
            for ( $i = 1; $i <= 5; $i++ ) {
                $job_summaries[] = $saved["job_summary_$i"] ?? '';
            }

            $has_new_format = ! empty( $saved['intro_text'] ) || ! empty( $saved['job_summary_1'] );

            if ( $has_new_format ) {
                return new WP_REST_Response( array(
                    'ok'           => true,
                    'status'       => 'completed',
                    'jobs'         => $jobs     ?: array(),
                    'ranking'      => $ranking  ?: array(),
                    'reasons'      => $reasons  ?: array(),
                    'intro_text'   => $saved['intro_text']  ?? '',
                    'job_summaries'=> $job_summaries,
                    'conclusion'   => $saved['conclusion']  ?? '',
                    'mbti_type'    => $saved['mbti_type']   ?? '',
                ), 200 );
            }

            // Legacy single-analysis format
            if ( $saved['analysis'] ) {
                return new WP_REST_Response( array(
                    'ok'       => true,
                    'status'   => 'completed',
                    'jobs'     => $jobs    ?: array(),
                    'ranking'  => $ranking ?: array(),
                    'reasons'  => $reasons ?: array(),
                    'analysis' => $saved['analysis'],
                    'mbti_type'=> $saved['mbti_type'] ?? '',
                ), 200 );
            }

            // Completed but no analysis saved — needs regeneration
            return new WP_REST_Response( array(
                'ok'                 => true,
                'status'             => 'completed',
                'needs_regeneration' => true,
                'jobs'               => $jobs    ?: array(),
                'ranking'            => $ranking ?: array(),
                'reasons'            => $reasons ?: array(),
                'mbti_type'          => $saved['mbti_type'] ?? '',
            ), 200 );
        }

        if ( $status === 'reasons' && ! empty( $jobs ) && ! empty( $ranking ) ) {
            return new WP_REST_Response( array(
                'ok'      => true,
                'status'  => 'reasons',
                'jobs'    => $jobs,
                'ranking' => $ranking,
                'reasons' => $reasons ?: array(),
            ), 200 );
        }

        if ( $status === 'in_progress' && ! empty( $jobs ) ) {
            return new WP_REST_Response( array(
                'ok'      => true,
                'status'  => 'in_progress',
                'jobs'    => $jobs,
                'ranking' => $ranking ?: array(),
            ), 200 );
        }

        return new WP_REST_Response( array( 'ok' => true, 'status' => 'not_started' ), 200 );
    }

    /* ── Submit ── */
    public function handle_submit( WP_REST_Request $req ) {
        global $wpdb;
        $user_id = $this->get_current_user_id();

        if ( ! $user_id ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'User not found' ), 400 );
        }

        try {
            $step    = sanitize_text_field( $req->get_param( 'step' ) );
            $jobs    = $req->get_param( 'jobs' );
            $ranking = $req->get_param( 'ranking' );
            $reasons = $req->get_param( 'reasons' );
            $table   = $wpdb->prefix . self::TABLE;

            // ── save_jobs ──────────────────────────────────────────────────
            if ( $step === 'save_jobs' ) {
                if ( ! is_array( $jobs ) || count( $jobs ) !== 5 ) {
                    return new WP_REST_Response( array( 'ok' => false, 'error' => 'Please enter exactly 5 jobs' ), 400 );
                }
                $wpdb->replace( $table, array(
                    'user_id'      => $user_id,
                    'jobs_json'    => wp_json_encode( $jobs ),
                    'ranking_json' => wp_json_encode( $jobs ),
                    'status'       => 'in_progress',
                ), array( '%d', '%s', '%s', '%s' ) );

                if ( function_exists( 'mfsd_set_task_status' ) && get_option( 'ai_junk_jobs_course_management', 1 ) ) {
                    mfsd_set_task_status( $user_id, 'junk_jobs', 'in_progress' );
                }
                return new WP_REST_Response( array( 'ok' => true, 'status' => 'in_progress' ), 200 );
            }

            // ── save_ranking ───────────────────────────────────────────────
            if ( $step === 'save_ranking' ) {
                if ( ! is_array( $ranking ) || count( $ranking ) !== 5 ) {
                    return new WP_REST_Response( array( 'ok' => false, 'error' => 'Invalid ranking' ), 400 );
                }
                $wpdb->replace( $table, array(
                    'user_id'      => $user_id,
                    'jobs_json'    => wp_json_encode( $jobs ),
                    'ranking_json' => wp_json_encode( $ranking ),
                    'status'       => 'reasons',
                ), array( '%d', '%s', '%s', '%s' ) );
                return new WP_REST_Response( array( 'ok' => true, 'status' => 'reasons' ), 200 );
            }

            // ── back_to_input ──────────────────────────────────────────────
            if ( $step === 'back_to_input' ) {
                $wpdb->replace( $table, array(
                    'user_id'      => $user_id,
                    'jobs_json'    => wp_json_encode( $jobs ),
                    'ranking_json' => NULL,
                    'reasons_json' => NULL,
                    'status'       => 'in_progress',
                ), array( '%d', '%s', '%s', '%s', '%s' ) );
                return new WP_REST_Response( array( 'ok' => true, 'status' => 'in_progress' ), 200 );
            }

            // ── back_to_rank ───────────────────────────────────────────────
            if ( $step === 'back_to_rank' ) {
                $wpdb->replace( $table, array(
                    'user_id'      => $user_id,
                    'jobs_json'    => wp_json_encode( $jobs ),
                    'ranking_json' => wp_json_encode( $ranking ),
                    'reasons_json' => NULL,
                    'status'       => 'in_progress',
                ), array( '%d', '%s', '%s', '%s', '%s' ) );
                return new WP_REST_Response( array( 'ok' => true, 'status' => 'in_progress' ), 200 );
            }

            // ── generate_analysis ──────────────────────────────────────────
            if ( $step === 'generate_analysis' ) {
                if ( ! is_array( $ranking ) || ! is_array( $reasons ) ) {
                    return new WP_REST_Response( array( 'ok' => false, 'error' => 'Missing ranking or reasons' ), 400 );
                }

                // Age / name
                $dob = get_user_meta( $user_id, 'date_of_birth', true );
                if ( $dob ) {
                    $age      = date_diff( date_create( $dob ), date_create( 'today' ) )->y;
                    $age_text = "a {$age}-year-old UK student";
                } else {
                    $age_text = "a UK student aged 11-14";
                }

                $username = wp_get_current_user()->display_name;
                $mwai     = $GLOBALS['mwai'] ?? null;

                $intro_text    = '';
                $job_summaries = array( '', '', '', '', '' );
                $conclusion    = '';

                if ( $mwai ) {
                    // ── 1. Intro ───────────────────────────────────────────
                    $intro_prompt  = "You are SteveGPT — Steve Sallis's AI careers coach using Steve's Solutions Mindset.\n\n";
                    $intro_prompt .= "Write a warm, encouraging 2-3 sentence opener for {$username}, {$age_text}. ";
                    $intro_prompt .= "They have just submitted 5 jobs they would NOT want to do. ";
                    $intro_prompt .= "Tell them that what they don't want is just as important as what they do want, ";
                    $intro_prompt .= "because every 'no thanks' reveals something brilliant about what they need from a career. ";
                    $intro_prompt .= "Start with 'Steve says:'. Be warm and direct. No jargon. Under 60 words.";

                    try {
                        $intro_text = $mwai->simpleTextQuery( $intro_prompt );
                    } catch ( Exception $e ) {
                        error_log( 'AI Junk Jobs intro failed: ' . $e->getMessage() );
                        $intro_text = "Steve says: {$username}, what you don't want to do tells us just as much about you as what you do want — every choice here is a clue about what really matters to you. Let's dig in.";
                    }

                    // ── 2. Individual job summaries ────────────────────────
                    foreach ( $ranking as $i => $job ) {
                        $reason = isset( $reasons[$job] ) ? $reasons[$job] : '';
                        $num    = $i + 1;

                        $job_prompt  = "You are SteveGPT — Steve Sallis's AI careers coach using Steve's Solutions Mindset.\n\n";
                        $job_prompt .= "You are writing for {$username}, {$age_text}. Keep your tone warm and encouraging.\n\n";
                        $job_prompt .= "{$username} ranked '{$job}' as their #{$num} most undesirable job.\n";
                        $job_prompt .= "Their reason: \"{$reason}\"\n\n";
                        $job_prompt .= "Write a focused 3-4 sentence analysis of THIS JOB ONLY. Structure it like this:\n";
                        $job_prompt .= "1) Briefly acknowledge their concern about {$job}\n";
                        $job_prompt .= "2) One Solutions Mindset reframe — turn their negative into a positive requirement.\n";
                        $job_prompt .= "   Examples: 'too dirty' becomes 'You need a clean, comfortable working environment'\n";
                        $job_prompt .= "   'dealing with angry people' becomes 'You need respectful, positive interactions at work'\n";
                        $job_prompt .= "   'boring and repetitive' becomes 'You need variety and intellectual challenge'\n";
                        $job_prompt .= "3) One or two quick UK facts: typical salary range and qualifications needed.\n\n";
                        $job_prompt .= "RULES: No headers. No bullet points. No markdown. Flowing sentences only. Under 80 words.";

                        try {
                            $job_summaries[$i] = $mwai->simpleTextQuery( $job_prompt );
                        } catch ( Exception $e ) {
                            error_log( "AI Junk Jobs job summary {$num} failed: " . $e->getMessage() );
                            $job_summaries[$i] = "Your feelings about {$job} make complete sense — and they tell us something important about what you DO need from a career.";
                        }
                    }

                    // ── 3. Conclusion ──────────────────────────────────────
                    $conclusion_prompt  = "You are SteveGPT — Steve Sallis's AI careers coach using Steve's Solutions Mindset.\n\n";
                    $conclusion_prompt .= "You have just analysed 5 junk jobs for {$username}, {$age_text}.\n\n";
                    $conclusion_prompt .= "Here are their 5 choices and reasons:\n";
                    foreach ( $ranking as $i => $job ) {
                        $reason = isset( $reasons[$job] ) ? $reasons[$job] : '';
                        $conclusion_prompt .= ( $i + 1 ) . ") {$job} — \"{$reason}\"\n";
                    }
                    $conclusion_prompt .= "\nWrite a 3-4 sentence synthesis that:\n";
                    $conclusion_prompt .= "1) Identifies 3-4 positive requirements {$username} DOES need in a career (pulled from all 5 reasons)\n";
                    $conclusion_prompt .= "2) Ends on an encouraging, forward-looking note\n";
                    $conclusion_prompt .= "3) Signs off with '- Steve'\n\n";
                    $conclusion_prompt .= "RULES: No headers. No bullet points. Flowing paragraphs only. Under 100 words.";

                    try {
                        $conclusion = $mwai->simpleTextQuery( $conclusion_prompt );
                    } catch ( Exception $e ) {
                        error_log( 'AI Junk Jobs conclusion failed: ' . $e->getMessage() );
                        $conclusion = "These five choices together paint a clear picture of what you need from a career, {$username}. Keep these requirements in mind as you explore what you DO want — they're the building blocks of a career that fits who you are.\n\n- Steve";
                    }
                }

                // ── Save to DB ─────────────────────────────────────────────
                $save_summary = get_option( 'ai_junk_jobs_save_summary', 1 );

                $data = array(
                    'user_id'      => $user_id,
                    'jobs_json'    => wp_json_encode( $jobs ),
                    'ranking_json' => wp_json_encode( $ranking ),
                    'reasons_json' => wp_json_encode( $reasons ),
                    'status'       => 'completed',
                );

                if ( $save_summary ) {
                    $data['intro_text']    = $intro_text;
                    $data['job_summary_1'] = $job_summaries[0];
                    $data['job_summary_2'] = $job_summaries[1];
                    $data['job_summary_3'] = $job_summaries[2];
                    $data['job_summary_4'] = $job_summaries[3];
                    $data['job_summary_5'] = $job_summaries[4];
                    $data['conclusion']    = $conclusion;
                }

                $format = array_fill( 0, count( $data ), '%s' );
                $format[0] = '%d';

                $wpdb->replace( $table, $data, $format );

                if ( function_exists( 'mfsd_set_task_status' )
                     && get_option( 'ai_junk_jobs_course_management', 1 )
                     && ( $intro_text || $job_summaries[0] ) ) {
                    mfsd_set_task_status( $user_id, 'junk_jobs', 'completed' );
                }

                return new WP_REST_Response( array(
                    'ok'           => true,
                    'ranking'      => $ranking,
                    'reasons'      => $reasons,
                    'intro_text'   => $intro_text,
                    'job_summaries'=> $job_summaries,
                    'conclusion'   => $conclusion,
                    'status'       => 'completed',
                ), 200 );
            }

            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Invalid step: ' . $step ), 400 );

        } catch ( Exception $e ) {
            error_log( 'Junk Jobs Submit Error: ' . $e->getMessage() );
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'Server error: ' . $e->getMessage() ), 500 );
        }
    }

    private function get_current_user_id() {
        return (int) get_current_user_id();
    }

    /* ================================================================
       ADMIN
       ================================================================ */
    public function admin_menu() {
        add_menu_page( 'Junk Jobs', 'Junk Jobs', 'manage_options', 'ai-junk-jobs',
            array( $this, 'admin_page' ), 'dashicons-hammer', 31 );
    }

    public function admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ( isset( $_POST['action'] ) && $_POST['action'] === 'save_jj_settings'
             && check_admin_referer( 'ai_junk_jobs_settings' ) ) {
            update_option( 'ai_junk_jobs_course_management', isset( $_POST['course_management'] ) ? 1 : 0 );
            update_option( 'ai_junk_jobs_save_summary',      isset( $_POST['save_summary'] )      ? 1 : 0 );
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        if ( isset( $_POST['action'] ) && $_POST['action'] === 'reset_jj_student'
             && check_admin_referer( 'ai_junk_jobs_reset_student' ) ) {
            $reset_uid = intval( $_POST['reset_user_id'] ?? 0 );
            if ( $reset_uid > 0 ) {
                $wpdb->delete( $table, array( 'user_id' => $reset_uid ) );
                if ( function_exists( 'mfsd_get_task_order_row' ) ) {
                    $wpdb->delete( $wpdb->prefix . 'mfsd_task_progress',
                        array( 'student_id' => $reset_uid, 'task_slug' => 'junk_jobs' ) );
                }
                $u    = get_user_by( 'id', $reset_uid );
                $name = $u ? $u->display_name : 'Student';
                echo '<div class="notice notice-success"><p>' . esc_html( $name ) . '\'s Junk Jobs data and progress have been reset.</p></div>';
            }
        }

        $cm           = get_option( 'ai_junk_jobs_course_management', 1 );
        $save_summary = get_option( 'ai_junk_jobs_save_summary', 1 );
        $students     = $wpdb->get_results(
            "SELECT j.user_id, j.status, j.intro_text, j.analysis, u.display_name
             FROM $table j JOIN {$wpdb->users} u ON u.ID = j.user_id
             ORDER BY u.display_name ASC"
        );
        ?>
        <div class="wrap">
            <h1>⚒️ Junk Jobs Settings</h1>

            <?php if ( ! function_exists( 'mfsd_get_task_status' ) ): ?>
                <div class="notice notice-warning">
                    <p><strong>MFSD Ordering Utility</strong> is not active — course management features will not function until it is activated.</p>
                </div>
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
                            <p class="description">When on: all 7 AI segments (intro, 5 job summaries, conclusion) are saved and reused on return visits. When off: regenerated each visit — useful for testing prompts.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" class="button button-primary" value="Save Settings"></p>
            </form>

            <hr>
            <h2>Student Records</h2>
            <?php if ( empty( $students ) ): ?>
                <p style="color:#999;">No students have started Junk Jobs yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Student</th><th>Status</th><th>Summary Saved</th><th>Ordering Status</th></tr></thead>
                    <tbody>
                        <?php foreach ( $students as $s ):
                            $order_status = function_exists( 'mfsd_get_task_status' ) ? mfsd_get_task_status( $s->user_id, 'junk_jobs' ) : '—';
                            $has_summary  = ! empty( $s->intro_text ) || ! empty( $s->analysis );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $s->display_name ); ?></strong></td>
                            <td><?php echo esc_html( $s->status ); ?></td>
                            <td><?php echo $has_summary ? '✓ Yes' : '✗ No'; ?></td>
                            <td><?php echo esc_html( $order_status ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr>
            <h2>Reset Student Answers</h2>
            <?php if ( empty( $students ) ): ?>
                <p style="color:#999;">No students have started Junk Jobs yet.</p>
            <?php else: ?>
            <form method="post" action="" onsubmit="return confirm('Permanently delete all Junk Jobs data for this student?');">
                <?php wp_nonce_field( 'ai_junk_jobs_reset_student' ); ?>
                <input type="hidden" name="action" value="reset_jj_student">
                <table class="form-table">
                    <tr>
                        <th><label for="reset_user_id">Select Student</label></th>
                        <td>
                            <select name="reset_user_id" id="reset_user_id" style="min-width:260px;">
                                <option value="">— select a student —</option>
                                <?php foreach ( $students as $s ):
                                    $os = function_exists( 'mfsd_get_task_status' ) ? ' — ' . mfsd_get_task_status( $s->user_id, 'junk_jobs' ) : '';
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