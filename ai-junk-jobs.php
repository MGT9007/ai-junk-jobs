<?php
/**
 * Plugin Name: AI Junk Jobs
 * Description: Students explore jobs they DON'T want, rank them, explain why, and get AI-powered insights to reframe into positive requirements. Use shortcode [ai_junk_jobs].
 * Version: 2.1.0
 * Author: MisterT9007
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AI_Junk_Jobs {
    const VERSION      = '2.1.0';
    const TABLE        = 'mfsd_ai_junk_jobs_results';
    const NONCE_ACTION = 'wp_rest';

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        add_action( 'init', array( $this, 'register_assets' ) );
        add_shortcode( 'ai_junk_jobs', array( $this, 'shortcode' ) );
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );

        // Force flush rewrite rules on version change
        add_action( 'plugins_loaded', array( $this, 'check_version' ) );
    }

    public function check_version() {
        $saved_version = get_option( 'ai_junk_jobs_version' );
        if ( $saved_version !== self::VERSION ) {
            flush_rewrite_rules();
            update_option( 'ai_junk_jobs_version', self::VERSION );
            error_log( 'AI Junk Jobs: Flushed rewrite rules for version ' . self::VERSION );
        }
    }

    public function on_activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            jobs_json LONGTEXT NULL,
            ranking_json LONGTEXT NULL,
            reasons_json LONGTEXT NULL,
            analysis LONGTEXT NULL,
            mbti_type CHAR(4) NULL,
            status VARCHAR(20) DEFAULT 'not_started',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user (user_id),
            KEY idx_status (status),
            UNIQUE KEY idx_user_unique (user_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // Force flush on activation
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
        // ── End ordering gate ──────────────────────────────────────────────

        wp_enqueue_script( $handle );
        wp_enqueue_style( $handle );

        $user_id = $this->get_current_user_id();

        $config = array(
            'restUrlSubmit' => esc_url_raw( rest_url( 'ai-junk-jobs/v1/submit' ) ),
            'restUrlStatus' => esc_url_raw( rest_url( 'ai-junk-jobs/v1/status' ) ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'user'          => is_user_logged_in() ? wp_get_current_user()->user_login : '',
            'email'         => is_user_logged_in() ? wp_get_current_user()->user_email : '',
            'userId'        => $user_id,
        );

        wp_add_inline_script(
            $handle,
            'window.AI_JUNK_JOBS_CFG = ' . wp_json_encode( $config ) . ';',
            'before'
        );

        return '<div id="ai-junk-jobs-root"></div>';
    }

    public function register_routes() {
        error_log( 'AI Junk Jobs: Registering REST routes' );
        
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

    public function handle_status( WP_REST_Request $req ) {
        global $wpdb;
        $user_id = $this->get_current_user_id();

        error_log( 'AI Junk Jobs Status: user_id=' . $user_id );

        if ( ! $user_id ) {
            return new WP_REST_Response( array(
                'ok' => true,
                'status' => 'not_started'
            ), 200 );
        }

        $table = $wpdb->prefix . self::TABLE;

        $saved = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d LIMIT 1",
            $user_id
        ), ARRAY_A );

        error_log( 'AI Junk Jobs Status: DB result=' . print_r( $saved, true ) );

        if ( ! $saved ) {
            return new WP_REST_Response( array(
                'ok' => true,
                'status' => 'not_started'
            ), 200 );
        }

        $jobs = json_decode( $saved['jobs_json'], true );
        $ranking = json_decode( $saved['ranking_json'], true );
        $reasons = json_decode( $saved['reasons_json'], true );
        $status = $saved['status'];
        $analysis = $saved['analysis'];

        if ( $status === 'completed' && $analysis ) {
            // Save summary ON — return stored analysis directly
            return new WP_REST_Response( array(
                'ok'       => true,
                'status'   => 'completed',
                'jobs'     => $jobs ?: array(),
                'ranking'  => $ranking ?: array(),
                'reasons'  => $reasons ?: array(),
                'analysis' => $analysis,
                'mbti_type' => $saved['mbti_type']
            ), 200 );
        } elseif ( $status === 'completed' && ! $analysis ) {
            // Save summary OFF — task is complete but no stored analysis.
            // Return all data plus a flag so the JS auto-regenerates the summary.
            return new WP_REST_Response( array(
                'ok'                 => true,
                'status'             => 'completed',
                'needs_regeneration' => true,
                'jobs'               => $jobs ?: array(),
                'ranking'            => $ranking ?: array(),
                'reasons'            => $reasons ?: array(),
                'mbti_type'          => $saved['mbti_type']
            ), 200 );
        } elseif ( $status === 'reasons' && ! empty( $jobs ) && ! empty( $ranking ) ) {
            return new WP_REST_Response( array(
                'ok' => true,
                'status' => 'reasons',
                'jobs' => $jobs,
                'ranking' => $ranking,
                'reasons' => $reasons ?: array()
            ), 200 );
        } elseif ( $status === 'in_progress' && ! empty( $jobs ) ) {
            return new WP_REST_Response( array(
                'ok' => true,
                'status' => 'in_progress',
                'jobs' => $jobs,
                'ranking' => $ranking ?: array()
            ), 200 );
        }

        return new WP_REST_Response( array(
            'ok' => true,
            'status' => 'not_started'
        ), 200 );
    }

    public function handle_submit( WP_REST_Request $req ) {
        global $wpdb;
        $user_id = $this->get_current_user_id();

        if ( ! $user_id ) {
            return new WP_REST_Response( array(
                'ok' => false,
                'error' => 'User not found'
            ), 400 );
        }

        try {
            $step = sanitize_text_field( $req->get_param( 'step' ) );
            $jobs = $req->get_param( 'jobs' );
            $ranking = $req->get_param( 'ranking' );
            $reasons = $req->get_param( 'reasons' );

            error_log( 'Junk Jobs Submit: step=' . $step . ', user_id=' . $user_id );

            $table = $wpdb->prefix . self::TABLE;

            // Step 1: Save initial jobs
            if ( $step === 'save_jobs' ) {
                if ( ! is_array( $jobs ) || count( $jobs ) !== 5 ) {
                    return new WP_REST_Response( array(
                        'ok' => false,
                        'error' => 'Please enter exactly 5 jobs'
                    ), 400 );
                }

                $data = array(
                    'user_id' => $user_id,
                    'jobs_json' => wp_json_encode( $jobs ),
                    'ranking_json' => wp_json_encode( $jobs ),
                    'status' => 'in_progress'
                );
                
                $format = array( '%d', '%s', '%s', '%s' );
                
                $result = $wpdb->replace( $table, $data, $format );

                error_log( 'Junk Jobs: save_jobs result=' . $result . ', error=' . $wpdb->last_error );

                if ( $result === false ) {
                    error_log( 'Junk Jobs DB Error (save_jobs): ' . $wpdb->last_error );
                }

                // ── Ordering: mark in_progress ─────────────────────────────
                if ( function_exists( 'mfsd_set_task_status' ) && get_option( 'ai_junk_jobs_course_management', 1 ) ) {
                    mfsd_set_task_status( $user_id, 'junk_jobs', 'in_progress' );
                }
                // ──────────────────────────────────────────────────────────

                return new WP_REST_Response( array(
                    'ok' => true,
                    'status' => 'in_progress'
                ), 200 );
            }

            // Step 2: Save ranking
            if ( $step === 'save_ranking' ) {
                if ( ! is_array( $ranking ) || count( $ranking ) !== 5 ) {
                    return new WP_REST_Response( array(
                        'ok' => false,
                        'error' => 'Invalid ranking'
                    ), 400 );
                }

                $data = array(
                    'user_id' => $user_id,
                    'jobs_json' => wp_json_encode( $jobs ),
                    'ranking_json' => wp_json_encode( $ranking ),
                    'status' => 'reasons'
                );
                
                $format = array( '%d', '%s', '%s', '%s' );
                
                $result = $wpdb->replace( $table, $data, $format );

                error_log( 'Junk Jobs: save_ranking result=' . $result );

                return new WP_REST_Response( array(
                    'ok' => true,
                    'status' => 'reasons'
                ), 200 );
            }

            // Step 3: Back to input
            if ( $step === 'back_to_input' ) {
                $data = array(
                    'user_id' => $user_id,
                    'jobs_json' => wp_json_encode( $jobs ),
                    'ranking_json' => NULL,
                    'reasons_json' => NULL,
                    'status' => 'in_progress'
                );
                
                $format = array( '%d', '%s', '%s', '%s', '%s' );
                
                $wpdb->replace( $table, $data, $format );

                return new WP_REST_Response( array(
                    'ok' => true,
                    'status' => 'in_progress'
                ), 200 );
            }

            // Step 4: Back to ranking
            if ( $step === 'back_to_rank' ) {
                $data = array(
                    'user_id' => $user_id,
                    'jobs_json' => wp_json_encode( $jobs ),
                    'ranking_json' => wp_json_encode( $ranking ),
                    'reasons_json' => NULL,
                    'status' => 'in_progress'
                );
                
                $format = array( '%d', '%s', '%s', '%s', '%s' );
                
                $wpdb->replace( $table, $data, $format );

                return new WP_REST_Response( array(
                    'ok' => true,
                    'status' => 'in_progress'
                ), 200 );
            }

            // Step 5: Generate analysis from reasons
            if ( $step === 'generate_analysis' ) {
                if ( ! is_array( $ranking ) || ! is_array( $reasons ) ) {
                    return new WP_REST_Response( array(
                        'ok' => false,
                        'error' => 'Missing ranking or reasons'
                    ), 400 );
                }

                // Get MBTI type from RAG assessment
                $mbti_type = null;
                $rag_table = $wpdb->prefix . 'mfsd_high_performance_pathway';
                $rag_data = $wpdb->get_row( $wpdb->prepare(
                    "SELECT mbti_type FROM $rag_table WHERE user_id = %d ORDER BY updated_at DESC LIMIT 1",
                    $user_id
                ), ARRAY_A );

                if ( $rag_data && ! empty( $rag_data['mbti_type'] ) ) {
                    $mbti_type = $rag_data['mbti_type'];
                }

                // Generate AI analysis
                $analysis = '';
                if ( isset( $GLOBALS['mwai'] ) ) {
                    try {
                        $mwai = $GLOBALS['mwai'];
                        $username = function_exists( 'um_get_display_name' ) 
                            ? um_get_display_name( $user_id ) 
                            : wp_get_current_user()->display_name;

                        $prompt = "You are a careers adviser using Steve's Solutions Mindset approach to help $username (a UK student aged 12-14) understand what they DON'T want in their career.\n\n";
                        
                        $prompt .= "$username has identified 5 jobs they'd least want to do, ranked from most undesirable to least:\n\n";
                        
                        foreach ( $ranking as $i => $job ) {
                            $reason = isset( $reasons[$job] ) ? $reasons[$job] : '';
                            $prompt .= ( $i + 1 ) . ") $job\n";
                            $prompt .= "   Why they don't want this: \"$reason\"\n\n";
                        }

                        $prompt .= "Your task:\n\n";
                        
                        $prompt .= "1) FOR EACH JOB:\n";
                        $prompt .= "   - Acknowledge their concern briefly\n";
                        $prompt .= "   - Provide 2-3 key facts about the job (UK salary range, typical qualifications, reality check)\n";
                        
                        if ( $mbti_type ) {
                            $prompt .= "   - Explain why their $mbti_type personality type means this job genuinely ISN'T a good fit (be reassuring)\n";
                        }
                        
                        $prompt .= "   - REFRAME their reason into a positive requirement using Solutions Mindset:\n";
                        $prompt .= "     * 'Doesn't pay well' → 'You need financial security and value'\n";
                        $prompt .= "     * 'Boring' → 'You need variety, creativity, and intellectual challenge'\n";
                        $prompt .= "     * 'People shout at you' → 'You need a respectful, supportive environment'\n";
                        $prompt .= "     * 'Too much pressure' → 'You need reasonable work-life balance'\n";
                        $prompt .= "   - CHALLENGE any limiting beliefs (e.g., 'I'm not good with people' becomes 'You haven't yet developed your interpersonal skills + here's how')\n\n";
                        
                        $prompt .= "2) SYNTHESIZE: What do these 5 junk jobs tell us about what $username DOES value in work? List 3-4 positive requirements.\n\n";
                        
                        $prompt .= "3) MARGINAL GAINS ACTION PLAN: Give 3 specific 1% actions $username can take THIS WEEK to reduce risk of ending up in a junk job:\n";
                        $prompt .= "   - One related to skills/learning\n";
                        $prompt .= "   - One related to exploration/research\n";
                        $prompt .= "   - One related to mindset/habits\n\n";
                        
                        $prompt .= "Keep the tone warm, reassuring, and empowering. This is about discovering what matters to them, not dwelling on what they don't want.";

                        $analysis = $mwai->simpleTextQuery( $prompt );

                    } catch ( Exception $e ) {
                        error_log( 'AI Junk Jobs analysis failed: ' . $e->getMessage() );
                        $analysis = '';
                    }
                }

                // Save — respect save_summary setting for analysis column only
                $save_summary = get_option( 'ai_junk_jobs_save_summary', 1 );

                $data = array(
                    'user_id'      => $user_id,
                    'jobs_json'    => wp_json_encode( $jobs ),
                    'ranking_json' => wp_json_encode( $ranking ),
                    'reasons_json' => wp_json_encode( $reasons ),
                    'analysis'     => $save_summary ? $analysis : null,
                    'mbti_type'    => $mbti_type,
                    'status'       => 'completed',
                );

                $format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

                $result = $wpdb->replace( $table, $data, $format );

                error_log( 'AI Junk Jobs: generate_analysis result=' . $result . ', error=' . $wpdb->last_error );

                if ( $result === false ) {
                    error_log( 'Junk Jobs DB Error (generate_analysis): ' . $wpdb->last_error );
                }

                // ── Ordering: mark completed after first analysis generation ──
                // Always marks completed regardless of save_summary — the task
                // is done. save_summary only controls whether the analysis text
                // is stored, not whether the student has completed the activity.
                if ( function_exists( 'mfsd_set_task_status' )
                     && get_option( 'ai_junk_jobs_course_management', 1 )
                     && $analysis ) {
                    mfsd_set_task_status( $user_id, 'junk_jobs', 'completed' );
                }
                // ──────────────────────────────────────────────────────────

                return new WP_REST_Response( array(
                    'ok'        => true,
                    'ranking'   => $ranking,
                    'reasons'   => $reasons,
                    'analysis'  => $analysis,
                    'mbti_type' => $mbti_type,
                    'status'    => 'completed'
                ), 200 );
            }

            return new WP_REST_Response( array(
                'ok' => false,
                'error' => 'Invalid step: ' . $step
            ), 400 );

        } catch ( Exception $e ) {
            error_log( 'Junk Jobs Submit Error: ' . $e->getMessage() );
            return new WP_REST_Response( array(
                'ok' => false,
                'error' => 'Server error: ' . $e->getMessage()
            ), 500 );
        }
    }

    private function get_current_user_id() {
        if ( function_exists( 'um_profile_id' ) ) {
            $pid = um_profile_id();
            if ( $pid ) return (int) $pid;
        }
        return (int) get_current_user_id();
    }

    // ─────────────────────────────────────────────
    // ADMIN
    // ─────────────────────────────────────────────

    public function admin_menu() {
        add_menu_page(
            'Junk Jobs',
            'Junk Jobs',
            'manage_options',
            'ai-junk-jobs',
            array( $this, 'admin_page' ),
            'dashicons-hammer',
            31
        );
    }

    public function admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // ── Handle settings save ──────────────────────────────────────────
        if ( isset( $_POST['action'] ) && $_POST['action'] === 'save_jj_settings'
             && check_admin_referer( 'ai_junk_jobs_settings' ) ) {

            update_option( 'ai_junk_jobs_course_management', isset( $_POST['course_management'] ) ? 1 : 0 );
            update_option( 'ai_junk_jobs_save_summary',      isset( $_POST['save_summary'] )      ? 1 : 0 );

            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        // ── Handle student reset ──────────────────────────────────────────
        if ( isset( $_POST['action'] ) && $_POST['action'] === 'reset_jj_student'
             && check_admin_referer( 'ai_junk_jobs_reset_student' ) ) {

            $reset_uid = intval( $_POST['reset_user_id'] ?? 0 );
            if ( $reset_uid > 0 ) {
                $wpdb->delete( $table, array( 'user_id' => $reset_uid ) );

                // Clear ordering progress
                if ( function_exists( 'mfsd_get_task_order_row' ) ) {
                    $wpdb->delete(
                        $wpdb->prefix . 'mfsd_task_progress',
                        array( 'student_id' => $reset_uid, 'task_slug' => 'junk_jobs' )
                    );
                }

                $u    = get_user_by( 'id', $reset_uid );
                $name = $u ? $u->display_name : 'Student';
                echo '<div class="notice notice-success"><p>' . esc_html( $name ) . '\'s Junk Jobs data and progress have been reset.</p></div>';
            }
        }

        // ── Current settings ──────────────────────────────────────────────
        $cm           = get_option( 'ai_junk_jobs_course_management', 1 );
        $save_summary = get_option( 'ai_junk_jobs_save_summary', 1 );

        // ── Students with data ────────────────────────────────────────────
        $students = $wpdb->get_results(
            "SELECT j.user_id, j.status, j.analysis,
                    u.display_name
             FROM   $table j
             JOIN   {$wpdb->users} u ON u.ID = j.user_id
             ORDER  BY u.display_name ASC"
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
                        <th scope="row">Course Management</th>
                        <td>
                            <label>
                                <input type="checkbox" name="course_management" value="1" <?php checked( $cm, 1 ); ?>>
                                <strong>Enable course ordering &amp; completion tracking</strong>
                            </label>
                            <p class="description">
                                When <strong>on</strong>: task locking and progress states are tracked via MFSD Course Manager.<br>
                                When <strong>off</strong>: ordering logic is bypassed entirely — useful for testing.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Save AI Summary</th>
                        <td>
                            <label>
                                <input type="checkbox" name="save_summary" value="1" <?php checked( $save_summary, 1 ); ?>>
                                <strong>Save AI summary to database</strong>
                            </label>
                            <p class="description">
                                When <strong>on</strong>: the AI analysis is persisted to the database and the task is marked
                                <em>completed</em> in course management (if enabled).<br>
                                When <strong>off</strong>: the AI analysis is generated and shown to the student but
                                <strong>not saved</strong> — they will need to regenerate on next visit. The course ordering
                                gate will <strong>not</strong> advance (stays <em>in_progress</em>). Useful for testing prompts
                                and AI output without affecting student records.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Save Settings">
                </p>
            </form>

            <hr>

            <h2>Student Records</h2>
            <?php if ( empty( $students ) ): ?>
                <p style="color:#999;">No students have started Junk Jobs yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Plugin Status</th>
                            <th>Summary Saved</th>
                            <th>Ordering Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $students as $s ):
                            $order_status = '—';
                            if ( function_exists( 'mfsd_get_task_status' ) ) {
                                $order_status = mfsd_get_task_status( $s->user_id, 'junk_jobs' );
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $s->display_name ); ?></strong></td>
                            <td><?php echo esc_html( $s->status ); ?></td>
                            <td><?php echo $s->analysis ? '✓ Yes' : '✗ No'; ?></td>
                            <td><?php echo esc_html( $order_status ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr>

            <h2>Reset Student Answers</h2>
            <p class="description">
                Deletes a student's Junk Jobs data and resets their course ordering progress back to <em>not started</em>.
                Use during testing or if a student needs to redo the activity.
            </p>
            <?php if ( empty( $students ) ): ?>
                <p style="color:#999;">No students have started Junk Jobs yet.</p>
            <?php else: ?>
            <form method="post" action=""
                  onsubmit="return confirm('This will permanently delete all Junk Jobs data for this student and reset their progress. Are you sure?');">
                <?php wp_nonce_field( 'ai_junk_jobs_reset_student' ); ?>
                <input type="hidden" name="action" value="reset_jj_student">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="reset_user_id">Select Student</label></th>
                        <td>
                            <select name="reset_user_id" id="reset_user_id" style="min-width:260px;">
                                <option value="">— select a student —</option>
                                <?php foreach ( $students as $s ):
                                    $order_status = '';
                                    if ( function_exists( 'mfsd_get_task_status' ) ) {
                                        $order_status = ' — ordering: ' . mfsd_get_task_status( $s->user_id, 'junk_jobs' );
                                    }
                                ?>
                                    <option value="<?php echo esc_attr( $s->user_id ); ?>">
                                        <?php echo esc_html( $s->display_name ); ?>
                                        (<?php echo esc_html( $s->status . $order_status ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-secondary"
                           value="Reset Selected Student"
                           style="color:#d63638; border-color:#d63638;">
                </p>
            </form>
            <?php endif; ?>

        </div>
        <?php
    }

}

new AI_Junk_Jobs();