<?php
/**
 * Created by PhpStorm.
 * User: alex
 * Date: 4/19/18
 * Time: 3:48 PM
 */

class Brizy_Public_CropProxy extends Brizy_Public_AbstractProxy {

	const ENDPOINT = 'brizy_media';
	const ENDPOINT_FILTER = 'brizy_crop';
	const ENDPOINT_POST = 'brizy_post';

	/**
	 * @return string
	 */
	protected function get_endpoint_key() {
		return self::ENDPOINT;
	}

	public function query_vars( $vars ) {
		$vars   = parent::query_vars( $vars );
		$vars[] = self::ENDPOINT_FILTER;
		$vars[] = self::ENDPOINT_POST;

		return $vars;
	}

	/**
	 * @return mixed|void
	 * @throws Exception
	 */
	public function process_query() {
		global $wp_query;

		$vars = $wp_query->query_vars;

		if ( ! isset( $vars[ self::ENDPOINT_FILTER ] ) || ! is_string( $vars[ self::ENDPOINT_FILTER ] ) || empty( $vars[ self::ENDPOINT_FILTER ] ) ) {
			return;
		}

		if ( ! isset( $vars[ self::ENDPOINT_POST ] ) || ! is_numeric( $vars[ self::ENDPOINT_POST ] ) ) {
			return;
		}

		if ( isset( $vars[ self::ENDPOINT ] ) && is_string( $vars[ self::ENDPOINT ] ) && ! empty( $vars[ self::ENDPOINT ] ) ) {

			try {
				if ( is_numeric( $vars[ self::ENDPOINT ] ) ) {
					$this->crop_local_asset( (int) $vars[ self::ENDPOINT ], urldecode( $vars[ self::ENDPOINT_FILTER ] ), (int) $vars[ self::ENDPOINT_POST ] );
				} else {
					$this->crop_external_asset( $vars[ self::ENDPOINT ], urldecode( $vars[ self::ENDPOINT_FILTER ] ), (int) $vars[ self::ENDPOINT_POST ] );
				}
			} catch (Exception $e) {
				Brizy_Logger::instance()->exception($e);
			}

		}
	}


	/**
	 * @param int $attachment_id
	 * @param $filter
	 * @param int $post_id
	 *
	 * @throws Exception
	 */
	private function crop_local_asset( $attachment_id, $filter, $post_id ) {
		try {

			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return;
			}

			$media_url = get_attached_file( $attachment_id );

			$project    = Brizy_Editor_Project::get();
			$brizy_post = Brizy_Editor_Post::get( $post_id );

			$media_cache     = new Brizy_Editor_CropCacheMedia( $project, $brizy_post );
			$crop_media_path = $media_cache->crop_media( $media_url, $filter );
			$this->send_file( $crop_media_path );

		} catch ( Exception $e ) {
			Brizy_Logger::instance()->exception( $e );
			throw new Exception( 'Unable to crop media' );
		}
	}

	/**
	 * @param $media
	 * @param $filter
	 * @param int $post_id
	 *
	 * @throws Exception
	 */
	private function crop_external_asset( $media, $filter, $post_id ) {

		try {
			$project    = Brizy_Editor_Project::get();
			$brizy_post = Brizy_Editor_Post::get( $post_id );

			$media_cache     = new Brizy_Editor_CropCacheMedia( $project, $brizy_post );
			$original_path   = $media_cache->download_original_image( $media );
			$crop_media_path = $media_cache->crop_media( $original_path, $filter );
			$this->send_file( $crop_media_path );

		} catch ( Exception $e ) {
			Brizy_Logger::instance()->exception( $e );
			throw new Exception( 'Unable to crop media' );
		}

	}

}