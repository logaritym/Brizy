<?php
/**
 * Created by PhpStorm.
 * User: alex
 * Date: 4/18/18
 * Time: 10:46 AM
 */

class Brizy_Editor_Asset_MediaAssetProcessor implements Brizy_Editor_Asset_ProcessorInterface {

	/**
	 * @var Brizy_Editor_Asset_Storage
	 */
	private $storage;

	/**
	 * Brizy_Editor_Asset_HtmlAssetProcessor constructor.
	 *
	 * @param Brizy_Editor_Asset_AbstractStorage $storage
	 */
	public function __construct( $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Find and cache all assets and replace the urls with new local ones.
	 *
	 * @param $content
	 *
	 * @return string
	 */
	public function process( $content ) {

		$content = $this->process_external_asset_urls( $content );

		return $content;
	}

	public function process_external_asset_urls( $content ) {

		$site_url = site_url();
		$site_url = str_replace(array('/','.'),array('\/','\.'), $site_url);

		preg_match_all( '/'.$site_url.'\/?(\?' . Brizy_Public_CropProxy::ENDPOINT . '=(.[^"\',\s)]*))/im', $content, $matches );

		if ( ! isset( $matches[0] ) || count( $matches[0] ) == 0 ) {
			return $content;
		}

		foreach ( $matches[0] as $i => $url ) {

			$parsed_url = parse_url( html_entity_decode( $matches[0][ $i ] ) );

			if ( ! isset( $parsed_url['query'] ) ) {
				continue;
			}

			parse_str( $parsed_url['query'], $params );


			if ( ! isset( $params[ Brizy_Public_CropProxy::ENDPOINT ] ) ) {
				continue;
			}

			$project     = Brizy_Editor_Project::get();
			$brizy_post  = Brizy_Editor_Post::get( (int) $params[ Brizy_Public_CropProxy::ENDPOINT_POST ] );
			$media_cache = new Brizy_Editor_CropCacheMedia( $project, $brizy_post );

			$new_url = null;

			if ( is_numeric( $params[ Brizy_Public_CropProxy::ENDPOINT ] ) ) {
				$media_path      = get_attached_file( (int) $params[ Brizy_Public_CropProxy::ENDPOINT ] );
				$crop_media_path = $media_cache->crop_media( $media_path, $params[ Brizy_Public_CropProxy::ENDPOINT_FILTER ] );
			} else {
				$original_path   = $media_cache->download_original_image( $params[ Brizy_Public_CropProxy::ENDPOINT ] );
				$crop_media_path = $media_cache->crop_media( $original_path, $params[ Brizy_Public_CropProxy::ENDPOINT_FILTER ] );
			}

			$urlBuilder      = new Brizy_Editor_UrlBuilder( $project, $brizy_post );
			$local_media_url = str_replace( $urlBuilder->upload_path(), $urlBuilder->upload_url(), $crop_media_path );

			$content = str_replace( $matches[0][ $i ], $local_media_url, $content );
		}

		return $content;
	}


//	public function process_external_asset_urls( $content ) {
//		$regex = Brizy_Config::MEDIA_IMAGE_URL_REGEX;
//		preg_match_all( "/{$regex}/im", $content, $matches );
//
//		foreach ( $matches[0] as $asset_url ) {
//			$new_url = $this->storage->store( $asset_url );
//			$content = str_replace( $asset_url, $new_url, $content );
//		}
//
//		return $content;
//	}
}