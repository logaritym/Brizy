<?php
/**
 * Created by PhpStorm.
 * User: alex
 * Date: 5/3/18
 * Time: 4:28 PM
 */

abstract class Brizy_Editor_Asset_StaticFile {
	/**
	 * @param $asset_source
	 * @param $asset_path
	 *
	 * @return bool
	 */
	protected function store_file( $asset_source, $asset_path ) {

		if ( file_exists( $asset_path ) ) {
			return true;
		}

		try {
			// check destination dir
			$dir_path = dirname( $asset_path );

			if ( ! file_exists( $dir_path ) ) {
				mkdir( $dir_path, 0777, true );
			}


			$http        = new WP_Http();
			$wp_response = $http->request( $asset_source );

			$code = wp_remote_retrieve_response_code( $wp_response );

			if ( is_wp_error( $wp_response ) || ! ( $code >= 200 && $code < 300 ) ) {
				return false;
			}

			$content = wp_remote_retrieve_body( $wp_response );

			file_put_contents( $asset_path, $content );

		} catch ( Exception $e ) {
			// clean up
			if ( $asset_path ) {
				@unlink( $asset_path );
			}

			return false;
		}

		return true;
	}

	/**
	 * Make sure the $asset_path is an existing file.
	 *
	 * @param $asset_path
	 * @param $post_id
	 * @param string $title
	 *
	 * @return bool|int
	 */
	public function attach_to_post( $asset_path, $post_id, $title = '' ) {

		if ( ! $post_id ) {
			return false;
		}
		if ( ! file_exists( $asset_path ) ) {
			return false;
		}

		$filetype = wp_check_filetype( $asset_path );

		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => $title ? $title : basename( $asset_path ),
			'post_content'   => '',
			'post_status'    => 'inherit'
		);

		$result = wp_insert_attachment( $attachment, $asset_path, $post_id );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		if(!function_exists('wp_generate_attachment_metadata'))
		{
			include_once ABSPATH."/wp-admin/includes/image.php";
		}

		$attach_data = wp_generate_attachment_metadata( $result, $asset_path );
		wp_update_attachment_metadata( $result,  $attach_data );

		return $result;
	}

	/**
	 * @param $filename
	 */
	public function send_file( $filename ){
		if ( file_exists( $filename ) ) {

			$content = file_get_contents( $filename );

			// send headers
			$headers                   = [];
			$headers['Content-Type']   = $this->get_mime( $filename, 1 );
			$headers['Content-Length'] = strlen( $content );
			$headers['Cache-Control']  = 'max-age=600';

			foreach ( $headers as $key => $val ) {
				if ( is_array( $val ) ) {
					$val = implode( ', ', $val );
				}

				header( "{$key}: {$val}" );
			}
			// send file content
			echo $content;
			exit;
		} else {
			global $wp_query;
			$wp_query->set_404();
			return;
		}

	}

	/**
	 * @param $filename
	 * @param int $mode
	 *
	 * @return mixed|string
	 */
	protected function get_mime( $filename, $mode = 0 ) {

		// mode 0 = full check
		// mode 1 = extension check only

		$mime_types = array(

			'txt'  => 'text/plain',
			'htm'  => 'text/html',
			'html' => 'text/html',
			'php'  => 'text/html',
			'css'  => 'text/css',
			'js'   => 'application/javascript',
			'json' => 'application/json',
			'xml'  => 'application/xml',
			'swf'  => 'application/x-shockwave-flash',
			'flv'  => 'video/x-flv',

			// images
			'png'  => 'image/png',
			'jpe'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpg'  => 'image/jpeg',
			'gif'  => 'image/gif',
			'bmp'  => 'image/bmp',
			'ico'  => 'image/vnd.microsoft.icon',
			'tiff' => 'image/tiff',
			'tif'  => 'image/tiff',
			'svg'  => 'image/svg+xml',
			'svgz' => 'image/svg+xml',

			// archives
			'zip'  => 'application/zip',
			'rar'  => 'application/x-rar-compressed',
			'exe'  => 'application/x-msdownload',
			'msi'  => 'application/x-msdownload',
			'cab'  => 'application/vnd.ms-cab-compressed',

			// audio/video
			'mp3'  => 'audio/mpeg',
			'qt'   => 'video/quicktime',
			'mov'  => 'video/quicktime',

			// adobe
			'pdf'  => 'application/pdf',
			'psd'  => 'image/vnd.adobe.photoshop',
			'ai'   => 'application/postscript',
			'eps'  => 'application/postscript',
			'ps'   => 'application/postscript',

			// ms office
			'doc'  => 'application/msword',
			'rtf'  => 'application/rtf',
			'xls'  => 'application/vnd.ms-excel',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'docx' => 'application/msword',
			'xlsx' => 'application/vnd.ms-excel',
			'pptx' => 'application/vnd.ms-powerpoint',


			// open office
			'odt'  => 'application/vnd.oasis.opendocument.text',
			'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		);

		$array = explode( '.', $filename );
		$str   = end( $array );
		$ext   = strtolower( $str );

		if ( function_exists( 'mime_content_type' ) && $mode == 0 ) {
			$mimetype = mime_content_type( $filename );

			return $mimetype;

		} elseif ( function_exists( 'finfo_open' ) && $mode == 0 ) {
			$finfo    = finfo_open( FILEINFO_MIME );
			$mimetype = finfo_file( $finfo, $filename );
			finfo_close( $finfo );

			return $mimetype;
		} elseif ( array_key_exists( $ext, $mime_types ) ) {
			return $mime_types[ $ext ];
		} else {
			return 'application/octet-stream';
		}
	}
}