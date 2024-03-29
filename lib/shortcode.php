<?php
define( 'EXT_ID_PREFIX', 'WN' );

global $anvato_player_index;
$anvato_player_index = 0;

/**
 * Generate parameters (json) for Anvato Shortcode
 *
 * @param  array $attr Array of shortcode attributes
 * @return array       List (json) of parameters
 */
function anvato_shortcode_get_parameters( $attr ) {
	global $anvato_player_index;
	$mcp          = anvato_settings()->get_mcp_options();
	$player       = anvato_settings()->get_options( Anvato_Settings::PLAYER_SETTINGS_KEY );
	$analytics    = anvato_settings()->get_options( Anvato_Settings::ANALYTICS_SETTINGS_KEY );
	$monetization = anvato_settings()->get_options( Anvato_Settings::MONETIZATION_SETTINGS_KEY );

	// Set the attributes which the shortcode can override
	$json = shortcode_atts(
		array(
			'mcp'       => $mcp['mcp']['id'],
			'width'     => $player['width'] . $player['height_type'],
			'height'    => $player['height'] . $player['width_type'],
			'video'     => null,
			'station'   => null,
			'ext_id'    => null,
			'sharelink' => null,
			'autoplay'  => false,
		),
		$attr,
		'anvplayer'
	);

	$video_ids = explode( ',', $json['video'] );
	if ( sizeof( $video_ids ) > 1 ) {
		unset( $json['video'] );
		$json['playlist'] = $video_ids;
	} elseif ( ! empty( $attr['playlist'] ) ) {
		unset( $json['video'] );
		$json['playlist'] = $attr['playlist'];
	} elseif ( ! empty( $attr['feed'] ) ) {
		unset( $json['video'] );

		$json['playlist']         = array();
		$json['playlist']['type'] = 'feed';
		$json['playlist']['id']   = $attr['feed'];
	} elseif ( ! empty( $json['ext_id'] ) ) {
		$extern_ids = explode( ',', $json['ext_id'] );
		$video_ids  = array();
		foreach ( $extern_ids as $extern_id ) {
			$video_ids[] = EXT_ID_PREFIX . $extern_id;
		}

		if ( sizeof( $video_ids ) > 1 ) {
			unset( $json['video'] );
			$json['playlist'] = $video_ids;
		} else {
			$json['video'] = $video_ids[0];
		}
	}

	unset( $json['ext_id'] );

	if ( ! empty( $json['sharelink'] ) ) {
		$json['shareLink'] = $json['sharelink'];
	} elseif ( ! empty( $player['default_share_link'] ) ) {
		$json['shareLink'] = $player['default_share_link'];
	}

	unset( $json['sharelink'] );

	if ( ! empty( $player['title_visible'] ) ) {
		$json['titleVisible'] = 'true' === $player['title_visible'] ? true : false;
	}

	if ( ! empty( $player['player_parameters'] ) ) {
		$embed_pars = json_decode( $player['player_parameters'], true );
		if ( ! empty( $embed_pars ) && is_array( $embed_pars ) ) {
			$json = array_merge( $json, $embed_pars );
		}
	}

	if ( ! empty( $mcp['mcp']['tkx_key'] ) ) {
		$json['accessKey']                = $mcp['mcp']['tkx_key'];
		$json['accessControl']['preview'] = false;
	}

	$json['autoplay']  = ( 'true' === $json['autoplay'] );
	$json['pInstance'] = 'p' . $anvato_player_index++;

	// Avaliable values
	$plugin_map = array(
		'analytics' => array(
			'pdb' => 'tracker_id',
		),
		'omniture'  => array(
			'account'        => 'adobe_account',
			'trackingServer' => 'adobe_trackingserver',
		),
		'heartbeat' => array(
			'account'              => 'heartbeat_account_id',
			'publisherId'          => 'heartbeat_publisher_id',
			'jobId'                => 'heartbeat_job_id',
			'marketingCloudId'     => 'heartbeat_marketing_id',
			'trackingServer'       => 'heartbeat_tracking_server',
			'customTrackingServer' => 'heartbeat_cstm_tracking_server',
			'chapterTracking'      => 'chapter_tracking',
			'version'              => 'heartbeat_version',
		),
		'comscore'  => array(
			'clientId' => 'comscore_client_id',
			'c3'       => 'comscore_c3',
		),
	);

	foreach ( $plugin_map as $name => $plugin ) {
		foreach ( $plugin as $field => $var ) {
			if ( ! empty( $analytics[ $var ] ) ) {
				$json['plugins'][ $name ][ $field ] = $analytics[ $var ];
				if ( 'chapterTracking' === $field ) {
					$json['plugins'][ $name ][ $field ] = ( 'true' === $analytics[ $var ] ? true : false );
				}
			}
		}
	}

	if ( ! empty( $analytics['google_account_id'] ) ) {
		$json['plugins']['googleAnalytics'] = json_decode( $analytics['google_account_id'], true );
	} else {
		if ( ! empty( $attr['plugin_google_trackerid'] ) && 'false' !== $attr['plugin_google_trackerid'] ) {
			$json['plugins']['googleAnalytics']['trackingId'] = $attr['plugin_google_trackerid'];
		}
	}

	foreach ( $mcp['owners'] as $owner ) {
		if ( $owner['id'] === $json['station'] && ! empty( $owner['access_key'] ) ) {
			$json['accessKey'] = $owner['access_key'];
			$json['token']     = 'default';
		}
	}

	unset( $json['station'] );

	//Special consideration for heartbeat analytics
	if (isset($analytics['heartbeat_account_id'])) {
		$account_obj = json_decode( $analytics['heartbeat_account_id'] );
		if ( is_object( $account_obj ) ) {
			$json['plugins']['heartbeat']['account'] = $account_obj;
		}
	}

	// Set the DFP Ad Tag, which can also be overridden
	if ( ! empty( $monetization['advanced_targeting'] ) ) {
		$json['plugins']['dfp'] = json_decode( $monetization['advanced_targeting'], true );
	} else {
		// User can close or change own dfp in shortcode
		if ( ! empty( $attr['plugin_dfp_adtagurl'] ) && 'false' !== $attr['plugin_dfp_adtagurl'] ) {
			$json['plugins']['dfp']['adTagUrl'] = urldecode( $attr['plugin_dfp_adtagurl'] );
		} elseif ( ! empty( $monetization['adtag'] ) ) {
			$json['plugins']['dfp']['adTagUrl'] = $monetization['adtag'];
		}
	}

	if ( isset( $attr['dfpkeyvalues'] ) ) {
		$dfp_kv = json_decode( $attr['dfpkeyvalues'], true );

		$json['plugins']['dfp']['clientSide']['keyValues'] =
				isset( $json['plugins']['dfp']['clientSide']['keyValues'] ) ?
					array_merge( $json['plugins']['dfp']['clientSide']['keyValues'], $dfp_kv ) : $dfp_kv;
	}

	//only in video mode, not in playilst mode
	if ( isset( $attr['no_pr'] ) && 'true' === $attr['no_pr'] && isset( $json['video'] ) ) {
		unset( $json['plugins']['dfp'] );
	}

	if ( isset( $json['video'] ) && is_string( $json['video'] ) && substr( $json['video'], 0, 1 ) === 'c' ) {
		$json['androidIntentPlayer'] = 'true';
	}

	# Allow theme/plugins to filter the JSON before outputting
	$json = apply_filters( 'anvato_anvp_json', $json, $attr );

	return array(
		'json'   => $json,
		'player' => $player,
	);
}

/**
 * Generate parameters (json) for Anvato Shortcode Exports (Google Amp, Facebook Instant Articles)
 *
 * @param  array $attr Array of shortcode attributes
 * @return array       List (json) of parameters
 */
function anvato_shortcode_get_parameters__for_exports( $attr ) {

	$parameters = anvato_shortcode_get_parameters( $attr );

	$parameters['json']['p']     = 'default';
	$parameters['json']['html5'] = true;

	unset( $parameters['json']['width'] );
	unset( $parameters['json']['height'] );
	unset( $parameters['json']['pInstance'] );

	$parameters['json']['m'] = $parameters['json']['mcp'];
	unset( $parameters['json']['mcp'] );

	if ( isset( $parameters['json']['video'] ) ) {
		$parameters['json']['v'] = $parameters['json']['video'];
		unset( $parameters['json']['video'] );
	}

	if ( isset( $parameters['json']['playlist'] ) ) {
		$parameters['json']['pl'] = $parameters['json']['playlist'];
		unset( $parameters['json']['playlist'] );
	}

	return $parameters;

}

add_filter( 'script_loader_tag', 'add_attribute_to_player_script', 10, 3 );

/**
 * Script script_loader_tag
 *
 * @param  string $tag Array of shortcode attributes
 * @param  string $handle unique name of the script
 * @param  string $src source string
 * @return string       string to replace the script
 */
function add_attribute_to_player_script( $tag, $handle, $src ) {
	if ( stripos( $handle, 'wp-anvato-player' ) === 0 ) {
		$params = wp_scripts()->get_data( $handle, 'wp-anvato-player-parameters' );
		$tag    = str_replace( '></', sprintf( " data-anvp='%s'></", esc_attr( $params ) ), $tag );
	}

	return $tag;
}

/**
 * Implement the Anvato shortcode.
 *
 * @param  array $attr Array of shortcode attributes
 * @return string       HTML to replace the shortcode
 */
function anvato_shortcode( $attr ) {

	$parameters = anvato_shortcode_get_parameters( $attr );

	// Set the player URL, which isn't part of the JSON but can be overridden
	$player_url = ! empty( $attr['player_url'] ) ? $attr['player_url'] : $parameters['player']['player_url'];
	$script_id  = 'wp-anvato-player-' . $parameters['json']['pInstance'];

	wp_enqueue_script( $script_id, esc_url( $player_url ), array(), false, true );
	wp_script_add_data( $script_id, 'wp-anvato-player-parameters', wp_json_encode( $parameters['json'] ) );

	// Player container
	return sprintf( "<div id='%s'></div>", esc_attr( $parameters['json']['pInstance'] ) );
}

add_shortcode( 'anvplayer', 'anvato_shortcode' );
