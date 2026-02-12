<?php
/**
 * Plugin Name: AI Junk Jobs
 * Description: Students explore jobs they DON'T want, rank them, explain why, and get AI-powered insights to reframe into positive requirements. Use shortcode [ai_junk_jobs].
 * Version: 1.0.0
 * Author: MisterT9007
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AI_Junk_Jobs {
    const VERSION      = '1.0.0';
    const TABLE        = 'mfsd_ai_junk_jobs_results';
    const NONCE_ACTION = 'wp_rest';

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        add_action( 'init', array( $this, 'register_assets' ) );
        add_shortcode( 'ai_junk_jobs', array( $this, 'shortcode' ) );
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        
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
            return new WP_REST_Response( array(
                'ok' => true,
                'status' => 'completed',
                'jobs' => $jobs ?: array(),
                'ranking' => $ranking ?: array(),
                'reasons' => $reasons ?: array(),
                'analysis' => $analysis,
                'mbti_type' => $saved['mbti_type']
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

                // Save with completed status
                $data = array(
                    'user_id' => $user_id,
                    'jobs_json' => wp_json_encode( $jobs ),
                    'ranking_json' => wp_json_encode( $ranking ),
                    'reasons_json' => wp_json_encode( $reasons ),
                    'analysis' => $analysis,
                    'mbti_type' => $mbti_type,
                    'status' => 'completed'
                );
                
                $format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' );
                
                $result = $wpdb->replace( $table, $data, $format );

                error_log( 'AI Junk Jobs: generate_analysis result=' . $result . ', error=' . $wpdb->last_error );

                if ( $result === false ) {
                    error_log( 'Junk Jobs DB Error (generate_analysis): ' . $wpdb->last_error );
                }

                return new WP_REST_Response( array(
                    'ok' => true,
                    'ranking' => $ranking,
                    'reasons' => $reasons,
                    'analysis' => $analysis,
                    'mbti_type' => $mbti_type,
                    'status' => 'completed'
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
}

new AI_Junk_Jobs();
