<?php
/**
 * Dream & Junk Jobs — prompt builders and marker parsers (spec §5).
 * Every parser removes its markers and never throws: a bad AI reply just loses that part.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AI_Junk_Jobs_AI {

    const BANDS = array(
        'natural' => 'Natural fit',
        'good'    => 'Good fit + stretch',
        'stretch' => 'Stretch zone',
    );

    public static function preamble( string $name, string $age_text ): string {
        // 7.0.1 (12a step 4): persona, tone and code rules live in the chatbot instructions only.
        return "This is for {$name}, {$age_text}. Speak directly to them (\"you\", \"your\").\n\n";
    }

    /** Personality context from mfsd_get_personality_profile() — the code is never included. */
    public static function profile_block( ?array $profile ): string {
        if ( ! $profile ) return '';
        $labels = array( 'energy' => 'Gets energy from', 'info' => 'Takes in information through',
                         'decisions' => 'Makes decisions with', 'structure' => 'Prefers things' );
        $axes = array();
        foreach ( $labels as $k => $label ) {
            if ( empty( $profile['axes'][ $k ] ) ) continue;
            $a      = $profile['axes'][ $k ];
            $axes[] = $label . ' ' . $a['lean'] . ' (' . ( $a['strength'] === 'strong' ? 'strong preference' : "close to the middle — go easy on this one" ) . ')';
        }
        return "PERSONALITY (from their Who Am I results): {$profile['name']} ({$profile['family']} family).\n"
             . "Strengths: {$profile['strengths']}\n"
             . ( $axes ? 'Preferences: ' . implode( '; ', $axes ) . ".\n" : '' ) . "\n";
    }

    public static function band_label( string $fit ): string {
        return self::BANDS[ $fit ] ?? '';
    }

    /* ── 5.1 Dream job, per job ─────────────────────────────────────── */
    public static function dream_prompt( string $name, string $age_text, string $job, int $n, string $reason, ?array $profile ): string {
        $p  = self::preamble( $name, $age_text );
        $p .= "{$name} ranked \"{$job}\" as their #{$n} dream job. Their reason: \"{$reason}\"\n";
        $p .= self::profile_block( $profile );
        $p .= "Write 3-4 flowing sentences: why it suits what they said, 2-3 key skills, "
            . "typical UK salary range (entry -> experienced), main UK routes (GCSEs/A-levels/T Levels/apprenticeships).\n";
        $p .= "RULES: no headers, no bullet points, no markdown. Under 110 words before the markers.\n";
        if ( $profile ) {
            $p .= "Then output on separate lines:\n"
                . "[FIT:natural|good|stretch]\n"
                . "[HELPS:strength one|strength two]\n"
                . "[STRETCH:one part that may not come naturally]\n"
                . "[BRIDGE:one practical strategy or stepping-stone]\n"
                . "Rules: bands are about personality only; stretch always has a bridge; 'close to the middle' preferences "
                . "can't be the only reason for stretch.\n";
        }
        // The safeguarding block is appended by SteveGPT 14 from the call's context (Prompt Layer §3.2).
        return $p;
    }

    /**
     * $concern: the reply's safeguarding severity from $bot->last_safeguarding_severity() ('' = none).
     * A safety reply carries no FIT markers worth reading.
     * @return array ['text' => string, 'fit' => ?array, 'concern' => string]
     */
    public static function parse_dream( string $raw, bool $has_profile, string $concern = '' ): array {
        $fit     = null;
        if ( $has_profile && $concern === '' ) {
            $band = strtolower( self::marker( $raw, 'FIT' ) );
            if ( isset( self::BANDS[ $band ] ) ) {
                $helps = array_values( array_filter( array_map( 'trim', explode( '|', self::marker( $raw, 'HELPS' ) ) ), 'strlen' ) );
                $fit   = array(
                    'fit'     => $band,
                    'helps'   => array_slice( array_map( 'sanitize_text_field', $helps ), 0, 3 ),
                    'stretch' => sanitize_text_field( self::marker( $raw, 'STRETCH' ) ),
                    'bridge'  => sanitize_text_field( self::marker( $raw, 'BRIDGE' ) ),
                );
            }
        }
        return array( 'text' => self::strip_markers( $raw ), 'fit' => $fit, 'concern' => $concern );
    }

    /* ── Intro (end of junk half) ──────────────────────────────────── */
    public static function intro_prompt( string $name, string $age_text ): string {
        return self::preamble( $name, $age_text )
             . "Write a warm, encouraging 2-3 sentence opener for {$name}. They have just shared 5 dream jobs AND 5 jobs they "
             . "would NOT want to do. Tell them that what they don't want is just as important as what they do want, because "
             . "putting the two lists side by side shows what they really need from a career. "
             . "Start with 'Steve says:'. Be warm and direct. No jargon. Under 60 words.";
    }

    /* ── 5.2 Junk job, per job ─────────────────────────────────────── */
    public static function junk_prompt( string $name, string $age_text, string $job, int $n, string $reason, ?array $profile, array $dream_lines ): string {
        $p  = self::preamble( $name, $age_text );
        $p .= "{$name} ranked '{$job}' as their #{$n} most undesirable job.\nTheir reason: \"{$reason}\"\n";
        $p .= self::profile_block( $profile );
        if ( $dream_lines ) {
            $p .= "Their dream jobs (for reference): " . implode( '; ', $dream_lines ) . "\n";
        }
        $p .= "\nWrite a focused 3-4 sentence analysis of THIS JOB ONLY. Structure it like this:\n"
            . "1) Briefly acknowledge their concern about {$job}\n"
            . "2) One Solutions Mindset reframe — turn their negative into a positive requirement.\n"
            . "   Examples: 'too dirty' becomes 'You need a clean, comfortable working environment'\n"
            . "   'dealing with angry people' becomes 'You need respectful, positive interactions at work'\n"
            . "   'boring and repetitive' becomes 'You need variety and intellectual challenge'\n"
            . "3) One or two quick UK facts: typical salary range and qualifications needed.\n\n"
            . "RULES: No headers. No bullet points. No markdown. Flowing sentences only. Under 80 words before the markers.\n\n"
            . "Output on separate lines:\n"
            . "[REQ:the positive requirement in 2-5 words, starting \"I need\"]\n";
        if ( $profile ) {
            $p .= "[CHECK:agrees|twist|neutral] — a twist is when the job actually uses their strengths\n"
                . "If a dream job shares strengths with this junk job, mention it in the twist sentence.\n";
        }
        return $p;
    }

    /** @return array ['text', 'req' => string, 'check' => ''|'agrees'|'twist'|'neutral', 'concern'] */
    public static function parse_junk( string $raw, bool $has_profile, string $concern = '' ): array {
        $req     = '';
        $check   = '';
        if ( $concern === '' ) {
            $req = sanitize_text_field( self::marker( $raw, 'REQ' ) );
            $req = trim( $req, " .\"'" );
            if ( $req !== '' && stripos( $req, 'i need' ) !== 0 ) $req = 'I need ' . lcfirst( $req );
            if ( strlen( $req ) > 60 ) $req = '';
            if ( $has_profile ) {
                $c = strtolower( self::marker( $raw, 'CHECK' ) );
                if ( in_array( $c, array( 'agrees', 'twist', 'neutral' ), true ) ) $check = $c;
            }
        }
        return array( 'text' => self::strip_markers( $raw ), 'req' => $req, 'check' => $check, 'concern' => $concern );
    }

    /* ── 5.3 Combined conclusion ───────────────────────────────────── */
    public static function conclusion_prompt( string $name, string $age_text, array $dream_lines, array $junk_lines, ?array $profile, array $requirements ): string {
        $p  = self::preamble( $name, $age_text );
        $p .= "You have analysed {$name}'s 5 dream jobs and 5 junk jobs.\n\n";
        $p .= "DREAM JOBS (ranked, with personality fit band and reason):\n" . implode( "\n", $dream_lines ) . "\n\n";
        $p .= "JUNK JOBS (ranked, with the requirement each reveals and personality check):\n" . implode( "\n", $junk_lines ) . "\n\n";
        $p .= self::profile_block( $profile );
        $p .= "REQUIREMENTS (use these exact names in MATCH lines): " . ( $requirements ? implode( ', ', $requirements ) : 'none' ) . "\n\n";
        $p .= "Write under 160 words:\n"
            . "1) Say what their two lists reveal together about what they need from a career.\n"
            . "2) Name the dream job that best meets their requirements AND is the most natural fit (if the same, celebrate it; if different, explain the trade-off kindly).\n"
            . "3) If there are plot twists, one sentence on how assumptions about jobs aren't always right.\n"
            . "4) Band words used must match the fit bands above exactly (Natural fit / Good fit + stretch / Stretch zone).\n"
            . "5) Exactly 3 one-percent actions this week: one skill, one explore, one habit. Each must be small, doable within a week, safe, "
            . "free or very low-cost, and something the student could show or tell a parent about.\n"
            . "6) Sign off \"- Steve\".\n"
            . "No headers, no markdown. Then for each dream job output one line:\n"
            . "[MATCH:{dream job}|{requirements met, comma-separated}|{requirements missing, comma-separated}]\n"
            . "Then output the three actions again, one per line, each under 120 characters, written as the student's own plan "
            . "(e.g. \"Ask a vet what the job is really like\"):\n"
            . "[ACTION:skill|{action}]\n[ACTION:explore|{action}]\n[ACTION:habit|{action}]";
        return $p;
    }

    /** @return array ['text' => string, 'match' => [job => ['met'=>[], 'missing'=>[]]], 'actions' => [kind => text]] */
    public static function parse_conclusion( string $raw, array $dream_ranking, array $requirements ): array {
        return array( 'text' => self::strip_markers( $raw ), 'match' => self::parse_match( $raw, $dream_ranking, $requirements ), 'actions' => self::parse_actions( $raw ) );
    }

    /** [ACTION:skill|…] lines → {skill, explore, habit}; malformed or over-long lines are dropped. */
    public static function parse_actions( string $raw ): array {
        $out = array();
        if ( preg_match_all( '/\[\s*ACTION\s*:\s*(skill|explore|habit)\s*\|([^\]]*)\]/i', $raw, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $a ) {
                $kind = strtolower( $a[1] );
                $text = trim( sanitize_text_field( $a[2] ), " \"'" );
                if ( $text === '' || isset( $out[ $kind ] ) || mb_strlen( $text ) > 140 ) continue;
                $out[ $kind ] = $text;
            }
        }
        $ordered = array();
        foreach ( array( 'skill', 'explore', 'habit' ) as $k ) if ( isset( $out[ $k ] ) ) $ordered[ $k ] = $out[ $k ];
        return $ordered;
    }

    private static function parse_match( string $raw, array $dream_ranking, array $requirements ): array {
        $match = array();
        if ( preg_match_all( '/\[\s*MATCH\s*:([^\]]*)\]/i', $raw, $m ) ) {
            foreach ( $m[1] as $line ) {
                $parts = array_map( 'trim', explode( '|', $line ) );
                $job   = self::find_ci( $parts[0] ?? '', $dream_ranking );
                if ( $job === null ) continue;
                $match[ $job ] = array(
                    'met'     => self::known_requirements( $parts[1] ?? '', $requirements ),
                    'missing' => self::known_requirements( $parts[2] ?? '', $requirements ),
                );
            }
        }
        return $match;
    }

    /** Matches AI requirement names case-insensitively (with or without "I need"); drops unknowns. */
    private static function known_requirements( string $csv, array $requirements ): array {
        $out = array();
        foreach ( explode( ',', $csv ) as $name ) {
            $name = strtolower( trim( $name, " .\"'" ) );
            if ( $name === '' || $name === 'none' ) continue;
            foreach ( $requirements as $req ) {
                $r = strtolower( $req );
                if ( $name === $r || $name === trim( preg_replace( '/^i need\s+/i', '', $r ) ) || 'i need ' . $name === $r ) {
                    if ( ! in_array( $req, $out, true ) ) $out[] = $req;
                    break;
                }
            }
        }
        return $out;
    }

    private static function find_ci( string $needle, array $haystack ): ?string {
        $needle = strtolower( trim( $needle, " #.\"'" ) );
        foreach ( $haystack as $h ) {
            if ( strtolower( $h ) === $needle ) return $h;
        }
        return null;
    }

    /** Value of the first [NAME:...] marker ('' if absent). */
    public static function marker( string $text, string $name ): string {
        return preg_match( '/\[\s*' . $name . '\s*:([^\]]*)\]/i', $text, $m ) ? trim( $m[1] ) : '';
    }

    /** Removes every [UPPERCASE:...] marker (and blank lines they leave behind). */
    public static function strip_markers( string $text ): string {
        $text = preg_replace( '/\[\s*[A-Z_]{2,}\s*:[^\]]*\]/i', '', $text );
        return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
    }

    /** Short "strength one, strength two" label for a dream job, used in junk prompts. */
    public static function dream_line( string $job, int $n, ?array $fit ): string {
        $line = "#{$n} {$job}";
        if ( $fit ) {
            $line .= ' (' . self::band_label( $fit['fit'] ) . ( $fit['helps'] ? '; uses ' . implode( ', ', $fit['helps'] ) : '' ) . ')';
        }
        return $line;
    }
}
