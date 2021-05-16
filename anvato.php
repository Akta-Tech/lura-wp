<?php
/*
	Plugin Name: Anvato Video Plugin
	Plugin URI: http://www.anvato.com/
	Description: This plugin allows a WordPress user to browse the Anvato Media Content Platform (MCP), choose a video and auto generate a shortcode to embed video into the post.
	Version: 1.1.2
	Author: Anvato
	Author URI: http://www.anvato.com/
*/
/*
	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


/**
 * Declare the necessary defines
 * Make sure to only declare them if they do not already exist,
 * just in case these are already declared custom
*/
if ( ! defined( 'ANVATO_PATH' ) ) {
	define( 'ANVATO_PATH', dirname( __FILE__ ) );
}
if ( ! defined( 'ANVATO_URL' ) ) {
	define( 'ANVATO_URL', trailingslashit( plugins_url( '', __FILE__ ) ) );
}
if ( ! defined( 'ANVATO_DOMAIN_SLUG' ) ) {
	define( 'ANVATO_DOMAIN_SLUG', 'wp_anvato' );
}

require_once ANVATO_PATH . '/lib/class-anvato-settings.php';
require_once ANVATO_PATH . '/lib/class-anvato-library.php';
require_once ANVATO_PATH . '/rest/class-anvato-rest-service.php';

if ( ! function_exists( 'get_current_screen' ) ) {
	require_once ABSPATH . '/wp-admin/includes/screen.php';
}

// Anvato Editor Implementations
if ( is_admin() ) {
	add_action(
		'current_screen',
		function () {
			$current_screen = get_current_screen();

			// Check whether gutenberg block editor is active or not
			if ( method_exists( $current_screen, 'is_block_editor' ) && $current_screen->is_block_editor() ) {
				require_once ANVATO_PATH . '/gutenberg/load.php';
			}
		}
	);

	require_once ANVATO_PATH . '/mexp/load.php';
} else {
	require_once ANVATO_PATH . '/lib/shortcode.php';
}

// Google AMP filter handler
add_action(
	'amp_content_embed_handlers',
	function( $list_of_embeds ) {
		if ( empty( $list_of_embeds ) ) {
			$list_of_embeds = array();
		}

		require_once( ANVATO_PATH . '/exports/class-anvato-amp-anvplayer-embed-handler.php' );
		$list_of_embeds['ANVATO_AMP_Anvplayer_Embed_Handler'] = array();

		return $list_of_embeds;
	}
);

// Facebook Instant Articles handler
add_filter(
	'feed_content_type',
	function( $content_type, $type ) {
		if ( defined( 'INSTANT_ARTICLES_SLUG' ) && INSTANT_ARTICLES_SLUG === $type ) {
			require_once ANVATO_PATH . '/exports/fia-anvplayer-embed.php';
		}
		return $content_type;
	},
	10,
	2
);

// Setup shortcode handle for FIA
if ( is_admin() ) {
	add_action(
		'instant_articles_before_transform_post',
		function ( $this_obj ) {
			/*
			Issue:
			Facebook Instant Articles generates post cache on post save.
			In this case, since the proper FIA AnvatoPlayer is not avaiable, shortcode is not handled.

			Solution:
			Check for the "instant_articles_before_transform_post",
			which is called right before save,
			and init dedicated Anvato Facebook Instant Articles shortcode.
			*/

			if ( ! function_exists( 'anvato_shortcode_get_parameters' ) ) {
				/*
				because "shortcode" is not included for Admin
				but it needs to be included to get shortcode options
				*/
				require_once ANVATO_PATH . '/lib/shortcode.php';
			}
			require_once ANVATO_PATH . '/exports/fia-anvplayer-embed.php';

		},
		10,
		1
	);
} // is_admin - instant_articles_before_transform_post

add_action(
	'rss2_ns', function () {
	echo 'xmlns:media="http://search.yahoo.com/mrss/" ';
}
);

add_action( 'rss2_item', 'add_feed_item_media' );

function xml_encode($s) {
	return str_replace(array('&','>','<','"'), array('&amp;','&gt;','&lt;','&quot;'), $s);
}

function get_mime_type($media_type) {
	switch($media_type) {
		case 'mp4':
			return 'video/mp4';
		case 'm3u8-variant':
			return 'application/x-mpegurl';
		case 'dash':
			return 'application/dash+xml';
		case 'jpg':
			return 'image/jpeg';
		default:
			return 'text/plain';
	}
}

function create_tkx_url($video_id, $station, $user_pars) {
	$feed_settings = anvato_settings()->feed_settings;
	$token = JWT::create_tkx_token($video_id, $station['access_key'], $station['secret_key'], $user_pars);
	$tkx_url = 'https://tkx.apis.anvato.net';
	if (isset($feed_settings['tkx_base_url']) && !empty($feed_settings['tkx_base_url'])) {
		$tkx_url = $feed_settings['tkx_base_url'];
	}
	$tkx_url = trim($tkx_url, '/');
	return $tkx_url . '/rest/v2/mcp/video/' . $video_id . '?anvack=' . $station['access_key'] . '&token=' . $token;
}

function add_feed_item_media() {
	global $post;

	$feed_settings = anvato_settings()->feed_settings;
	if (!$feed_settings) {
		return;
	}
	$media_types = [];
	foreach (['include_hls', 'include_dash', 'include_mp4'] as $media_type) {
		if (isset($feed_settings[$media_type])) {
			$media_types[] = $media_type;
		}
	}
	if (!count($media_types) === 0) {
		return;
	}

	if (!has_shortcode($post->post_content, 'anvplayer')) {
		return;
	}
	require_once ANVATO_PATH . '/lib/JWT.php';

	preg_match_all('/\[anvplayer video="(\d+)" station="(\d+)"\]/', $post->post_content, $matches);
	if (!$matches || count($matches) !== 3) {
		return;
	}

	$video_id = $matches[1][0];
	$station_id = $matches[2][0];
	$station = null;

	$mcp = anvato_settings()->get_mcp_options();
	if (!isset($mcp['owners'])) {
		return;
	}
	foreach ($mcp['owners'] as $owner) {
		if (!isset($owner['id']) || !isset($owner['access_key']) || !isset($owner['secret_key'])) {
			continue;
		}
		if ($owner['id'] === $station_id) {
			$station = $owner;
			break;
		}
	}
	if (!$station) {
		return;
	}

	// Add poster
	$poster_format = 'jpg';
	$user_pars = [
		'return_format' => 'redirect',
		'stream_format' => $poster_format
	];
	$poster_url = create_tkx_url($video_id, $station, $user_pars);
	echo '<media:thumbnail url="' . xml_encode($poster_url) . '"/>';

	$is_group = count($media_types) > 1;
	if ($is_group) {
		echo '<media:group>';
	}
	foreach ($media_types as $media_type) {
		$user_pars = [
			'return_format' => 'redirect',
			'stream_format' => $feed_settings[$media_type]
		];
		$video_url = create_tkx_url($video_id, $station, $user_pars);
		$mime_type = get_mime_type($feed_settings[$media_type]);
		echo '<media:content url="' . xml_encode($video_url) . '" type="' . $mime_type . '" />';
	}
	if ($is_group) {
		echo '</media:group>';
	}
}

//TEMP
function do_not_cache_feeds(&$feed) {
	$feed->enable_cache(false);
}

add_action( 'wp_feed_options', 'do_not_cache_feeds' );
//TEMP
