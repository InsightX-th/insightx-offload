<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Assets — Assets Pull: serve enqueued theme/plugin/core CSS & JS
 * through a CDN domain that fronts the site.
 *
 * Unlike offloaded media, theme/plugin asset files are never uploaded to
 * the storage bucket — they stay on the server and the CDN caches/proxies
 * them. So this rewrites `style_loader_src` / `script_loader_src` from the
 * site's own host to the configured CDN domain (same-origin assets only),
 * with an optional force-HTTPS scheme.
 *
 * Known limitation, surfaced in the UI copy: fonts and images referenced
 * *inside* a rewritten stylesheet still resolve relative to the CDN origin
 * (the site), so they keep being served from the server unless the CDN
 * does content rewriting (e.g. CloudFront + Lambda@Edge). Media-library
 * files remain covered by the regular media delivery settings.
 *
 * @since 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Assets {

    public function __construct() {
        add_filter( 'style_loader_src', [ $this, 'rewrite_src' ], 20 );
        add_filter( 'script_loader_src', [ $this, 'rewrite_src' ], 20 );
    }

    /**
     * Rewrite an enqueued asset URL to the CDN domain.
     *
     * @param string $src Original asset URL (absolute or relative).
     * @return string Rewritten URL, or the original when not applicable.
     */
    public function rewrite_src( $src ) {
        if ( is_admin() || is_feed() ) {
            return $src;
        }

        $s = ISXM_Settings::all();
        if ( empty( $s['assets_enabled'] ) ) {
            return $src;
        }
        $domain = trim( (string) $s['assets_cdn_domain'] );
        $domain = preg_replace( '#^https?://#', '', untrailingslashit( $domain ) );
        if ( $domain === '' ) {
            return $src;
        }

        // Already on the CDN — never rewrite twice.
        if ( strpos( $src, $domain ) !== false ) {
            return $src;
        }

        // Normalize relative srcs (some themes enqueue protocol-relative or
        // bare paths) against the site home before comparing hosts.
        if ( strpos( $src, '//' ) !== 0 && ! preg_match( '#^https?://#i', $src ) ) {
            $src = home_url( ltrim( $src, '/' ) );
        }

        $host = wp_parse_url( $src, PHP_URL_HOST );
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        // Only same-origin assets are safe to remap — an external CDN/font
        // host must keep pointing where it points.
        if ( ! $host || ! $site_host || $host !== $site_host ) {
            return $src;
        }

        $path = wp_parse_url( $src, PHP_URL_PATH );
        $query = wp_parse_url( $src, PHP_URL_QUERY );

        $scheme = ! empty( $s['assets_force_https'] )
            ? 'https'
            : ( wp_parse_url( $src, PHP_URL_SCHEME ) ?: ( is_ssl() ? 'https' : 'http' ) );

        $new = $scheme . '://' . $domain . ( $path !== null ? $path : '/' );
        if ( $query !== null && $query !== '' ) {
            $new .= '?' . $query;
        }
        return $new;
    }
}
