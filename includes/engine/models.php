<?php
/**
 * Model selection helpers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_model_fallback_chain')) {
    function cbia_model_fallback_chain($preferred) {
        $chain = [
            'gpt-5',
            'gpt-5-mini',
            'gpt-5-nano',
            'gpt-5.2',
            'gpt-4.1-mini',
            'gpt-4.1',
            'gpt-4.1-nano',
        ];

        $preferred = trim((string)$preferred);
        if ($preferred !== '') {
            if (!in_array($preferred, $chain, true)) {
                array_unshift($chain, $preferred);
            } else {
                $chain = array_values(array_unique(array_merge([$preferred], $chain)));
            }
        }

        return $chain;
    }
}

if (!function_exists('cbia_is_responses_model')) {
    function cbia_is_responses_model($m) {
        $m = strtolower(trim((string)$m));
        if ($m === '') return false;

        if (preg_match('/^gpt-5(\.[0-9]+)?(\-|$)/', $m)) return true;
        if ($m === 'gpt-5-mini' || $m === 'gpt-5-nano') return true;

        if (strpos($m, 'gpt-4.1') === 0) return true;
        if ($m === 'gpt-4o-mini') return true;

        return false;
    }
}

if (!function_exists('cbia_pick_model')) {
    function cbia_pick_model() {
        $s = cbia_get_settings();
        $preferred = $s['openai_model'] ?? 'gpt-4.1-mini';

        $chain = cbia_model_fallback_chain($preferred);
        foreach ($chain as $m) {
            return $m;
        }
        // Si no hay candidatos, devolvemos preferido igualmente
        return $preferred ?: 'gpt-4.1-mini';
    }
}

