<?php if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

class Brizy_Editor_Post extends Brizy_Admin_Serializable {

	const BRIZY_POST = 'brizy-post';
	const BRIZY_POST_SIGNATURE_KEY = 'brizy-post-signature';
	const BRIZY_POST_HASH_KEY = 'brizy-post-hash';
	const BRIZY_POST_EDITOR_VERSION = 'brizy-post-editor-version';


	/**
	 * @var Brizy_Editor_API_Page
	 */
	protected $api_page;

	/**
	 * @var int
	 */
	protected $wp_post_id;

	/**
	 * @var WP_Post
	 */
	protected $wp_post;

	/**
	 * @var string
	 */
	protected $compiled_html;

	/**
	 * @var string
	 */
	protected $compiled_html_body;

	/**
	 * @var string
	 */
	protected $compiled_html_head;

	/**
	 * @var bool
	 */
	protected $needs_compile;

	/**
	 * Json for the editor.
	 *
	 * @var string
	 */
	protected $editor_data;

	/**
	 * @var string
	 */
	protected $uid;


	/**
	 * @var Brizy_Editor_CompiledHtml
	 */
	static private $compiled_page;

	/**
	 * Brizy_Editor_Post constructor.
	 *
	 * @param $wp_post_id
	 */
	public function __construct( $wp_post_id ) {
		$this->wp_post_id = (int) $wp_post_id;
		$this->wp_post    = get_post( $this->wp_post_id );
		$this->uid        = $this->create_uid();
	}

	/**
	 * @return string
	 */
	public function serialize() {
		$get_object_vars = get_object_vars( $this );

		unset( $get_object_vars['wp_post_id'] );
		unset( $get_object_vars['wp_post'] );
		unset( $get_object_vars['api_page'] );
		unset( $get_object_vars['store_assets'] );
		unset( $get_object_vars['assets'] );

		return serialize( $get_object_vars );
	}

	/**
	 * @param $data
	 */
	public function unserialize( $data ) {
		parent::unserialize( $data ); // TODO: Change the autogenerated stub

		if ( $this->get_api_page() ) {
			$save_data = $this->get_api_page()->get_content();

			$this->editor_data = $save_data;
		}

		unset( $this->api_page );
	}

	/**
	 * @param $apost
	 *
	 * @return Brizy_Editor_Post
	 * @throws Brizy_Editor_Exceptions_NotFound
	 * @throws Brizy_Editor_Exceptions_UnsupportedPostType
	 */
	public static function get( $apost ) {

		$wp_post_id = $apost;

		if ( $apost instanceof WP_Post ) {
			$wp_post_id = $apost->ID;
		}
		$type = get_post_type( $wp_post_id );

		$supported_post_types   = brizy()->supported_post_types();
		$supported_post_types[] = 'revision';

		if ( ! in_array( $type, $supported_post_types ) ) {
			throw new Brizy_Editor_Exceptions_UnsupportedPostType(
				"Brizy editor doesn't support '{$type}' post type 1"
			);
		}

		$brizy_editor_storage_post = Brizy_Editor_Storage_Post::instance( $wp_post_id );

		$post = $brizy_editor_storage_post->get( self::BRIZY_POST );

		$post->wp_post_id = $wp_post_id;
		$post->wp_post    = get_post( $wp_post_id );
		$post->create_uid();

		return $post;
	}


	/**
	 * @return Brizy_Editor_Post[]
	 * @throws Brizy_Editor_Exceptions_NotFound
	 * @throws Brizy_Editor_Exceptions_UnsupportedPostType
	 */
	public static function get_all_brizy_posts() {
		global $wpdb;
		$posts = $wpdb->get_results(
			$wpdb->prepare( "SELECT pm.*, p.post_type FROM {$wpdb->postmeta} pm 
									JOIN {$wpdb->posts} p ON p.ID=pm.post_id  
									WHERE pm.meta_key = %s ", Brizy_Editor_Storage_Post::META_KEY )
		);

		$result = array();
		foreach ( $posts as $p ) {
			if ( in_array( $p->post_type, brizy()->supported_post_types() ) ) {
				$result[] = Brizy_Editor_Post::get( $p->post_id );
			}
		}

		return $result;
	}

	/**
	 * @param $project
	 * @param $post
	 *
	 * @return Brizy_Editor_Post
	 * @throws Brizy_Editor_Exceptions_UnsupportedPostType
	 * @throws Exception
	 */
	public static function create( $project, $post ) {
		if ( ! in_array( ( $type = get_post_type( $post->ID ) ), brizy()->supported_post_types() ) ) {
			throw new Brizy_Editor_Exceptions_UnsupportedPostType(
				"Brizy editor doesn't support '$type' post type 2"
			);
		}
		Brizy_Logger::instance()->notice( 'Create post', array( $project, $post ) );

		$post = new self( $post->ID );

		return $post;
	}

	public function save_revision( $revision_id ) {

		update_metadata( 'post', $revision_id, self::BRIZY_POST_SIGNATURE_KEY, Brizy_Editor_Signature::get() );

		if ( $this->get_api_page() ) {
			update_metadata( 'post', $revision_id, self::BRIZY_POST_HASH_KEY, $this->get_api_page()->get_id() );
		}

		$storage       = Brizy_Editor_Storage_Post::instance( $revision_id );
		$storage_value = $this->storage()->get_storage();

		if ( count( $storage_value ) == 0 ) {
			return;
		}

		$storage->loadStorage( $storage_value );
	}

	public function restore_from_revision( $revision_id ) {

		$storage = Brizy_Editor_Storage_Post::instance( $revision_id );

		/**
		 * @var Brizy_Editor_Post $revision_storage
		 */
		$revision_storage = $storage->get_storage();

		if ( ! $revision_storage || count( $revision_storage ) == 0 ) {
			return;
		}

		$this->storage()->loadStorage( $revision_storage );

		$signature = get_metadata( 'post', $revision_id, self::BRIZY_POST_SIGNATURE_KEY, true );
		if ( $signature ) {
			update_post_meta( $this->get_parent_id(), self::BRIZY_POST_SIGNATURE_KEY, $signature );
		}

		$hash_key = get_metadata( 'post', $this->get_parent_id(), self::BRIZY_POST_HASH_KEY, true );

		if ( $hash_key ) {
			update_post_meta( $this->get_parent_id(), self::BRIZY_POST_HASH_KEY, $hash_key );
		}

		if ( $revision_storage['brizy-post']->get_api_page() ) {
			$this->set_editor_data( $revision_storage['brizy-post']->get_api_page() );
		} else {
			$this->set_editor_data( $revision_storage['brizy-post']->get_editor_data() );
		}

		$this->api_page = null; //$revision_storage['brizy-post']->get_api_page();

		if ( $revision_storage['brizy-post']->get_compiled_html() ) {
			$this->set_compiled_html( $revision_storage['brizy-post']->get_compiled_html() );
		} else {
			$this->set_needs_compile( true )
			     ->set_compiled_html_head( null )
			     ->set_compiled_html_body( null );
		}
		$this->save();
	}

	/**
	 * @return bool
	 */
	public function save() {

		try {
			//$brizy_editor_user = Brizy_Editor_User::get();
			//$project           = Brizy_Editor_Project::get();
			//$api_project       = $project->get_api_project();
			//$updated_page      = $brizy_editor_user->update_page( $api_project, $this->api_page );
			//$this->updatePageData( $updated_page );

			// store the signature only once
			//if ( ! ( $signature = get_post_meta( $this->wp_post_id, self::BRIZY_POST_SIGNATURE_KEY, true ) ) ) {
			//update_post_meta( $this->wp_post_id, self::BRIZY_POST_SIGNATURE_KEY, Brizy_Editor_Signature::get() );
			//update_post_meta( $this->wp_post_id, self::BRIZY_POST_HASH_KEY, $this->get_api_page()->get_id() );
			//}

			update_post_meta( $this->wp_post_id, self::BRIZY_POST_EDITOR_VERSION, BRIZY_EDITOR_VERSION );

			$this->storage()->set( self::BRIZY_POST, $this );

		} catch ( Exception $exception ) {
			Brizy_Logger::instance()->exception( $exception );

			return false;
		}
	}

	/**
	 * @return bool
	 * @throws Brizy_Editor_Exceptions_ServiceUnavailable
	 * @throws Exception
	 */
	public function compile_page() {

		Brizy_Logger::instance()->notice( 'Compile page', array( $this ) );

		$compiled_html = Brizy_Editor_User::get()->compile_page( Brizy_Editor_Project::get(), $this );

		$this->set_compiled_html( $compiled_html );

		$this->set_compiled_html_head( null );
		$this->set_compiled_html_body( null );

		$this->set_needs_compile( false );

		update_post_meta( $this->wp_post_id, self::BRIZY_POST_EDITOR_VERSION, BRIZY_EDITOR_VERSION );

		$this->save();

		return true;
	}

	public function get_compiled_page( $project ) {

		if ( self::$compiled_page ) {
			return self::$compiled_page;
		}

		$brizy_editor_editor_editor = Brizy_Editor_Editor_Editor::get( $project, $this );
		$config                     = $brizy_editor_editor_editor->config();
		$asset_storage              = new Brizy_Editor_Asset_AssetProxyStorage( $project, $this, $config );
		$media_storage              = new Brizy_Editor_Asset_MediaProxyStorage( $project, $this, $config );

		$asset_processors   = array();
		$asset_processors[] = new Brizy_Editor_Asset_DomainProcessor();
		$asset_processors[] = new Brizy_Editor_Asset_AssetProxyProcessor( $asset_storage );
		$asset_processors[] = new Brizy_Editor_Asset_MediaAssetProcessor( $media_storage );

		$brizy_editor_compiled_html = new Brizy_Editor_CompiledHtml( $this->get_compiled_html() );
		$brizy_editor_compiled_html->setAssetProcessors( $asset_processors );

		return self::$compiled_page = $brizy_editor_compiled_html;
	}

	public function get_compiler_version() {
		$get_post_meta = get_post_meta( $this->wp_post_id, self::BRIZY_POST_EDITOR_VERSION, true );

		return $get_post_meta;
	}

	public function isCompiledWithCurrentVersion() {
		return $this->get_compiler_version() == BRIZY_EDITOR_VERSION;
	}

	/**
	 * @deprecated;
	 */
	public function get_api_page() {

		if ( isset( $this->api_page ) ) {
			return $this->api_page;
		}

		return null;
	}

	/**
	 * @return mixed
	 */
	public function get_id() {
		return $this->wp_post_id;
	}

	/**
	 * A unique id assigned when brizy is enabled for this post
	 *
	 * @return string
	 */
	public function create_uid() {

		if ( $this->uid ) {
			return $this->uid;
		}

		$this->uid = get_post_meta( $this->wp_post_id, 'brizy_post_uid', true );

		if ( ! $this->uid ) {
			$this->uid = md5( $this->wp_post_id . time() );
			update_post_meta( $this->wp_post_id, 'brizy_post_uid', $this->uid );
		}

		return $this->uid;
	}

	/**
	 * @return string
	 */
	public function get_uid() {
		return $this->uid;
	}

	/**
	 * @return string
	 */
	public function get_editor_data() {
		return isset( $this->editor_data ) ? $this->editor_data : '';
	}

	/**
	 * @param $content
	 *
	 * @return $this
	 */
	public function set_editor_data( $content ) {
		$this->editor_data = $content;

		return $this;
	}

	/**
	 * @return false|int|mixed
	 */
	public function get_parent_id() {
		$id = wp_is_post_revision( $this->get_id() );

		if ( ! $id ) {
			$id = $this->get_id();
		}

		return $id;
	}

	/**
	 * @return string
	 */
	public function get_compiled_html() {
		return $this->compiled_html;
	}

	/**
	 * @param string $compiled_html
	 *
	 * @return Brizy_Editor_Post
	 */
	public function set_compiled_html( $compiled_html ) {
		$this->compiled_html = $compiled_html;

		return $this;
	}

	/**
	 * @deprecated use get_compiled_html
	 * @return string
	 */
	public function get_compiled_html_body() {
		return $this->compiled_html_body;
	}

	/**
	 * @deprecated use get_compiled_html
	 * @return string
	 */
	public function get_compiled_html_head() {
		return $this->compiled_html_head;
	}

	/**
	 * @deprecated use set_compiled_html
	 *
	 * @param $html
	 *
	 * @return $this
	 */
	public function set_compiled_html_body( $html ) {
		$this->compiled_html_body = $html;

		return $this;
	}

	/**
	 * @deprecated use set_compiled_html
	 *
	 * @param $html
	 *
	 * @return $this
	 */
	public function set_compiled_html_head( $html ) {
		// remove all title and meta tags.
		$this->compiled_html_head = $html;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function can_edit() {
		return Brizy_Editor::is_capable( "edit_post", $this->get_id() );
	}

	/**
	 * @return $this
	 * @throws Brizy_Editor_Exceptions_AccessDenied
	 */
	public function enable_editor() {
		if ( ! $this->can_edit() ) {
			throw new Brizy_Editor_Exceptions_AccessDenied( 'Current user cannot edit page' );
		}

		$this->storage()->set( Brizy_Editor_Constants::USES_BRIZY, 1 );

		$this->create_uid();

		return $this;
	}

	/**
	 * @return $this
	 * @throws Brizy_Editor_Exceptions_AccessDenied
	 */
	public function disable_editor() {
		if ( ! $this->can_edit() ) {
			throw new Brizy_Editor_Exceptions_AccessDenied( 'Current user cannot edit page' );
		}

		$this->storage()->delete( Brizy_Editor_Constants::USES_BRIZY );

		return $this;
	}

	/**
	 * @return Brizy_Editor_Storage_Post
	 */
	public function storage() {
		return Brizy_Editor_Storage_Post::instance( $this->wp_post_id );
	}

	/**
	 * @return array|null|WP_Post
	 */
	public function get_wp_post() {
		return $this->wp_post;
	}

	/**
	 * @return bool
	 */
	public function uses_editor() {

		try {
			$brizy_editor_storage_post = $this->storage();

			return (bool) $brizy_editor_storage_post->get( Brizy_Editor_Constants::USES_BRIZY );
		} catch ( Exception $exception ) {
			return false;
		}
	}


	/**
	 * @return string
	 */
	public function edit_url() {
		return add_query_arg(
			array( Brizy_Editor_Constants::EDIT_KEY => '' ),
			get_permalink( $this->get_parent_id() )
		);
	}

	/**
	 * @param $v
	 *
	 * @return $this
	 */
	public function set_needs_compile( $v ) {
		$this->needs_compile = (bool) $v;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function get_needs_compile() {
		return $this->needs_compile;
	}

	/**
	 * @param $text
	 * @param string $tags
	 * @param bool $invert
	 *
	 * @return null|string|string[]
	 */
	function strip_tags_content( $text, $tags = '', $invert = false ) {

		preg_match_all( '/<(.+?)[\s]*\/?[\s]*>/si', trim( $tags ), $tags );
		$tags = array_unique( $tags[1] );

		if ( is_array( $tags ) AND count( $tags ) > 0 ) {
			if ( $invert == false ) {
				return preg_replace( '@<(?!(?:' . implode( '|', $tags ) . ')\b)(\w+)\b.*?>(.*?</\1>)?@si', '', $text );
			} else {
				return preg_replace( '@<(' . implode( '|', $tags ) . ')\b.*?>(.*?</\1>)?@si', '', $text );
			}
		} elseif ( $invert == false ) {
			return preg_replace( '@<(\w+)\b.*?>.*?</\1>@si', '', $text );
		}

		return $text;
	}

	/**
	 * @return array
	 */
	public function get_templates() {
		$type = get_post_type( $this->get_id() );
		$list = array(
			array(
				'id'    => '',
				'title' => __( 'Default' )
			)
		);

		return apply_filters( "brizy:$type:templates", $list );
	}

	/**
	 * @param string $atemplate
	 *
	 * @return $this
	 */
	public function set_template( $atemplate ) {

		if ( $atemplate == '' ) {
			delete_post_meta( $this->get_id(), '_wp_page_template' );
		} else {
			update_post_meta( $this->get_id(), '_wp_page_template', $atemplate );
		}

		return $this;
	}
}

