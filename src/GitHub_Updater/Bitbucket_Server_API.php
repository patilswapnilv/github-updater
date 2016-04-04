<?php
/**
 * GitHub Updater
 *
 * @package   GitHub_Updater
 * @author    Andy Fragen
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 */

/** 
 * BjornW TODO: 
 * - paging API support, for we're limited to Bitbucket Server default limit of 25
 * - personal repositories are not yet supported, using project based repositories 
 **/

namespace Fragen\GitHub_Updater;

/*
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Bitbucket_Server_API
 *
 * Get remote data from a self-hosted Bitbucket Server repo. 
 * Assumes an owner == project_key
 *
 * @package Fragen\GitHub_Updater
 * @author  Andy Fragen. Bjorn Wijers
 */
class Bitbucket_Server_API extends API {

	/**
	 * Constructor.
	 *
	 * @param object $type
	 */
	public function __construct( $type ) {
		$this->type     = $type;
		if ( defined(WP_DEBUG) && true === WP_DEBUG) {
			parent::$hours = 0.0001;  // setting transients to 0.36 sec 
		} else {
			parent::$hours  = 12;
		}
		$this->response = $this->get_transient();

		add_filter( 'http_request_args', array( &$this, 'maybe_authenticate_http' ), 10, 2 );
		add_filter( 'http_request_args', array( &$this, 'http_release_asset_auth' ), 15, 2 );

		if ( ! isset( self::$options['bitbucket_username'] ) ) {
			self::$options['bitbucket_username'] = null;
		}
		if ( ! isset( self::$options['bitbucket_password'] ) ) {
			self::$options['bitbucket_password'] = null;
		}
		add_site_option( 'github_updater', self::$options );
	}

	/**
	 * Read the remote file and parse headers. 
	 *
	 * @param $file
	 *
	 * @return bool
	 */
	public function get_remote_info( $file ) {
		$response = isset( $this->response[ $file ] ) ? $this->response[ $file ] : false;

		if ( ! $response ) {
			if ( empty( $this->type->branch ) ) {
				$this->type->branch = 'master';
			}

			$response = $this->api( '/1.0/projects/:owner/repos/:repo/browse/'  . $file . '?at=' . ( $this->type->branch ) );

			if ( $response ) {
				$contents = $this->_recombine_response( $response );
				$response = $this->get_file_headers( $contents, $this->type->type );
				$this->set_transient( $file, $response );
			}
		}

		if ( $this->validate_response( $response ) || ! is_array( $response ) ) {
			return false;
		}

		$this->set_file_info( $response );

		return true;
	}


	/** 
	 * Combines separate text lines from API response 
	 * into one string with \n line endings. 
	 * Code relying on raw text can now parse it. 
	 *
	 * @param array response 
	 *
	 * @return string combined lines of text returned by API
	 */
	private function _recombine_response( $response ) {
		$remote_info_file = '';
		if( is_array( $response->lines) ) {
			foreach( $response->lines as $line ) {
				$remote_info_file .= $line->text . "\n";
			}
		} 
		#log::write2log( 'combiner: ' . $remote_info_file ); 
		return $remote_info_file;
	}




	/**
	 * Get the remote info to for tags.
	 *
	 * @return bool
	 */
	public function get_remote_tag() {
		$repo_type = $this->return_repo_type();
		$response  = isset( $this->response['tags'] ) ? $this->response['tags'] : false;

		if ( $this->exit_no_update( $response ) ) {
			return false;
		}

		if ( ! $response ) {
			$response = $this->api( '/1.0/projects/:owner/repos/:repo/tags' );

			if ( ! $response || ( isset( $response->size) && $response->size < 1) || isset( $response->errors) ) {
				$response = new \stdClass();
				$response->message = 'No tags found';
			}

			if ( $response ) {
				$this->set_transient( 'tags', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->parse_tags( $response, $repo_type );

		return true;
	}

	/**
	 * Read the remote CHANGES.md file
	 *
	 * @param $changes
	 *
	 * @return bool
	 */
	public function get_remote_changes( $changes ) {
		$response = isset( $this->response['changes'] ) ? $this->response['changes'] : false;


		/*
		 * Set $response from local file if no update available.
		 */
		if ( ! $response && ! $this->can_update( $this->type ) ) {
			$response = new \stdClass();
			$content = $this->get_local_info( $this->type, $changes );
			if ( $content ) {
				$response->data = $content;
				$this->set_transient( 'changes', $response );
			} else {
				$response = false;
			}
		}

		if ( ! $response ) {
			if ( ! isset( $this->type->branch ) ) {
				$this->type->branch = 'master';
			}

			// use a constructed url to fetch the raw file response
			// due to lack of file dowload option in Bitbucket Server
			$raw_file_response = $this->_fetch_raw_file( $changes ); 

			if ( ! $raw_file_response )  {
				$response          = new \stdClass();
				$response->message = 'No changelog found';
			} else { 
				$response = new \stdClass();
				$response->data = wp_remote_retrieve_body( $raw_file_response );  
				$this->set_transient( 'changes', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$changelog = isset( $this->response['changelog'] ) ? $this->response['changelog'] : false;

		if ( ! $changelog ) {
			$parser    = new \Parsedown;
			$changelog = $parser->text( $response->data );
			$this->set_transient( 'changelog', $changelog );
		}

		$this->type->sections['changelog'] = $changelog;

		return true;
	}

	/**
	 * Read and parse remote readme.txt.
	 *
	 * @return bool
	 */
	public function get_remote_readme() {
		$readme = 'readme.txt'; 
		if ( ! file_exists( $this->type->local_path . $readme) &&
			! file_exists( $this->type->local_path_extended . $readme )
		) {
			return false;
		}

		$response = isset( $this->response['readme'] ) ? $this->response['readme'] : false;

		/*
		 * Set $response from local file if no update available.
		 */
		if ( ! $response && ! $this->can_update( $this->type )  ) {
			$response = new \stdClass();
			$content = $this->get_local_info( $this->type, $readme );
			if ( $content ) {
				$response->data = $content;
			} else {
				$response = false;
			}
		}

		if ( ! $response ) {
			if ( ! isset( $this->type->branch ) ) {
				$this->type->branch = 'master';
			}

			$raw_file_response = $this->_fetch_raw_file( $readme );

			if ( ! $raw_file_response ) {
				$response = new \stdClass();
				$response->message = 'No readme found';
			} else {
				$response = new \stdClass();
				$response->data = wp_remote_retrieve_body( $raw_file_response );  
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		if ( $response ) {
			$parser   = new Readme_Parser;
			$readme_parse_result = $parser->parse_readme( $response->data );
			$this->set_transient( 'readme', $response );
		}


		$this->set_readme_info( $readme_parse_result );

		return true;
	}

	/** 
	 * The Bitbucket Server REST API does not support downloading files directly at the moment
	 * therefor we'll use this to construct urls to fetch the raw files ourselves. 
	 *
	 * @param string filename
	 * @return bool false upon failure || return wp_remote_get() response array  
	 **/ 
	private function _fetch_raw_file( $file ) {
		$file = urlencode( $file ); 
		$download_url = implode( '/', array( $this->type->enterprise, 'projects',$this->type->owner, 'repos', $this->type->repo, 'browse', $file ) );
		$download_url = add_query_arg( array( 'at' => $this->type->branch, 'raw' => ''), $download_url );

		$response = wp_remote_get( esc_url_raw($download_url) );  

		Log::write2log( '_fetch_raw_file download_url: ' . $download_url . 'response: ' .print_r( $response, true ));
		if( is_wp_error( $response) ) {
			return false; 
		} 

		return $response;   
	}


	/**
	 * Read the repository meta from API
	 *
	 * @return bool
	 */
	public function get_repo_meta() {
		$response = isset( $this->response['meta'] ) ? $this->response['meta'] : false;

		if ( $this->exit_no_update( $response ) ) {
			return false;
		}

		if ( ! $response ) {
			$response = $this->api( '/1.0/projects/:owner/repos/:repo' );

			if ( $response ) {
				$this->set_transient( 'meta', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->repo_meta = $response;
		$this->_add_meta_repo_object();

		return true;
	}

	/**
	 * Create array of branches and download links as array.
	 *
	 * @return bool
	 */
	public function get_remote_branches() {
		$branches = array();
		$response = isset( $this->response['branches'] ) ? $this->response['branches'] : false;

		if ( $this->exit_no_update( $response, true ) ) {
			return false;
		}

		if ( ! $response ) {
			$response = $this->api( '/1.0/projects/:owner/repos/:repo/branches' );
			if ( $response ) {
				foreach ( $response as $branch => $api_response ) {
					$branches[ $branch ] = $this->construct_download_link( false, $branch );
				}
				$this->type->branches = $branches;
				$this->set_transient( 'branches', $branches );
				return true;
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->branches = $response;

		return true;
	}

	/**
	 * Construct $this->type->download_link using Bitbucket API
	 *
	 * @param boolean $rollback for theme rollback
	 * @param boolean $branch_switch for direct branch changing
	 *
	 * @return string $endpoint
	 */
	public function construct_download_link( $rollback = false, $branch_switch = false ) {

		// Downloads requires the forked stash-archive plugin which enables 
		// subdirectory support using the prefix query argument
		// see https://bitbucket.org/BjornW/stash-archive/src
		// the jar-file directory contains a jar file for convenience so you don't have
		// to install the Atlassian SDK
		$download_url = implode( '/', array( $this->type->enterprise,'plugins', 'servlet', 'archive', 'projects',  $this->type->owner, 'repos', $this->type->repo ) );

		// add a prefix query argument to create a subdirectory with the same name 
		// as the repo, e.g. 'my-repo' becomes 'my-repo/'
		$download_url = add_query_arg( 'prefix', $this->type->repo . '/', $download_url );
    
		if ( 'master' != $this->type->branch || empty( $this->type->tags ) ) {
			$download_url = add_query_arg( 'at', $this->type->branch, $download_url );
		} else {
			$download_url = add_query_arg( 'at', $this->type->newest_tag, $download_url );
		}

		if ( $branch_switch ) {
			$download_url = add_query_arg( 'at', $branch_switch, $download_url );
		}

		Log::write2log('construct_download_url: ' . $download_url);
		return $download_url;
	}

	/**
	 * Add remote data to type object.
	 * @access private
	 */
	private function _add_meta_repo_object() {
		// $this->type->rating       = $this->make_rating( $this->type->repo_meta );
		// $this->type->last_updated = $this->type->repo_meta->updated_on;
		// $this->type->num_ratings  = $this->type->watchers; 

		// Use the inverse. E.g. if public is true, return false so private is false and thus publicly accessible 
		log::write2log( 'repo meta public:  ' . ! $this->type->repo_meta->project->public); 
		$this->type->private      = ! $this->type->repo_meta->project->public; 
	}

	/**
	 * Add Basic Authentication $args to http_request_args filter hook
	 * for private Bitbucket repositories only.
	 *
	 * @param  $args
	 * @param  $url
	 *
	 * @return mixed $args
	 */
	public function maybe_authenticate_http( $args, $url ) {
		if ( ! isset( $this->type ) || false === stristr( $url, 'bitbucket' ) ) {
			return $args;
		}

		$bitbucket_private         = false;
		$bitbucket_private_install = false;

		/*
		 * Check whether attempting to update private Bitbucket repo.
		 */
		if ( isset( $this->type->repo ) &&
			! empty( parent::$options[ $this->type->repo ] ) &&
			false !== strpos( $url, $this->type->repo )
		) {
			$bitbucket_private = true;
		}

		/*
		 * Check whether attempting to install private Bitbucket repo
		 * and abort if Bitbucket user/pass not set.
		 */
		if ( isset( $_POST['option_page'], $_POST['is_private'] ) &&
			'github_updater_install' === $_POST['option_page'] &&
			'bitbucket' === $_POST['github_updater_api'] &&
			( ! empty( parent::$options['bitbucket_username'] ) || ! empty( parent::$options['bitbucket_password'] ) )
		) {
			$bitbucket_private_install = true;
		}

		if ( $bitbucket_private || $bitbucket_private_install ) {
			$username = parent::$options['bitbucket_username'];
			$password = parent::$options['bitbucket_password'];
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( "$username:$password" );
		}

		return $args;
	}

	/**
	 * Removes Basic Authentication header for Bitbucket Release Assets.
	 * Storage in AmazonS3 buckets, uses Query String Request Authentication Alternative.
	 * @link http://docs.aws.amazon.com/AmazonS3/latest/dev/RESTAuthentication.html#RESTAuthenticationQueryStringAuth
	 *
	 * @param $args
	 * @param $url
	 *
	 * @return mixed
	 */
	public function http_release_asset_auth( $args, $url ) {
		$arrURL = parse_url( $url );
		if ( 'bbuseruploads.s3.amazonaws.com' === $arrURL['host'] ) {
			unset( $args['headers']['Authorization'] );
		}

		return $args;
	}

	/**
	 * Added due to abstract class designation, not used for Bitbucket.
	 *
	 * @param $git
	 * @param $endpoint
	 */
	protected function add_endpoints( $git, $endpoint ) {}

}
