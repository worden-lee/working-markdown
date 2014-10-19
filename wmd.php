<?php

$WW_DIR = realpath( __DIR__ . '/../workingwiki' );

$ww_sources = array(
	'WorkingWiki.defs.php',
	'misc.php',
	'WWInterface.php',
	'WWStorage.php',
	'ProjectDescription.php',
	'WorkingWikiProjectDescription.php',
	'ProjectEngineConnection.php',
	'ProjectEngine/ProjectEngine.php',
	// TODO fill out this list thoughtfully
);

foreach ( $ww_sources as $php ) {
	require_once "$WW_DIR/$php";
}

$wwClickToAdd = false;
$wwLogFunction = function ( $string ) { print $string . "\n"; };
$wwUseHTTPForPE = false;
$wwPECanReadFilesFromWiki = $wwPEFilesAreAccessible = true;
$math_repl = "'$1<source-file filename=\"'.md5('$2').'.tex-math\" project=\"'.\$GLOBALS['default_project_directory'].'/.workingwiki/standalone/'.md5('$2').'\">$2</source-file>'";
$wwWikitextReplacements = array(
	'/([^\\\\]|^)\{\$(.*?[^\\\\]|)\$\}/es' => $math_repl,
        '/([^\\\\]|^)\$\$(.*?[^\\\\]|)\$\$/e' => $math_repl,
        '/([^\\\\]|^)<latex>(.*?[^\\\\]|)<\/latex>/esi' =>
                "'$1<source-file filename=\"'.md5('$2')"
                . ".'.tex-inline\" project=\"'.\$GLOBALS['default_project_directory'].'/.workingwiki/standalone/'.md5('$2').'\">"
                . "\\documentclass{article}\n"
                . "\\begin{document}\n"
                . "$2\n\\end{document}\n</source-file>'",
        '/__DISABLE_MAKE__/' => '<toggle-make-enabled enabled=0/>',
        '/__ENABLE_MAKE__/' => '<toggle-make-enabled enabled=1/>',
);

$peAllowProcessingInPlace = true;

class WMDInterface extends WWInterface {
	public $head_insertions = '';

	public function reconcile_token_with_project( $token, $token_start, $token_length, $pagename ) {
		global $wwContext;
		#wwLog( 'token: ' . json_encode( $this->uniq_tokens[$token] ) );
		$project = $wwContext->wwStorage->find_project_by_name(
				$this->uniq_tokens[$token]['args']['project']
			);
		$this->uniq_tokens[$token]['project'] = $project;
		#wwLog( 'for project: ' . $this->uniq_tokens[$token]['project']->project_name() );
		//$this->uniq_tokens[$token]['file_content'] = null;
		$args = $this->uniq_tokens[$token]['args'];
		$source = ( $this->uniq_tokens[$token]['tag'] == 'source-file' );
		if ( ! isset( $args['filename'] ) or $args['filename'] == '' ) {
			$this->uniq_tokens[$token]['failed'] = true;
			$sstr = ( $source ? 'source' : 'project' );
			$this->throw_error("Can not find filename for "
			  . "$sstr-file tag.  Please check for typing errors." );
		}
		else if ( ! ProjectDescription::is_allowable_filename( $args['filename'] ) ) {
			$this->uniq_tokens[$token]['failed'] = true;
			$this->throw_error(
				'Prohibited filename \''
				. htmlspecialchars($args['filename'])
				. '\''
			);
		}
		if ( $source and ! isset( $project->project_files[ $args['filename'] ] ) ) {
			$project->add_source_file( array(
				'filename' => $args['filename'],
				'page' => $project->project_page(),
			) );
		}
	}

	public function page_is_history() {
		return false;
	}

	public function sse_key_if_any() {
		return null;
	}

	public function amend_PE_request( &$request ) {
		parent::amend_PE_request( $request );
		$request['log-to-stdout'] = true;
	}

	/* TODO: syntax highlighting */
	public function hasSyntaxHighlighter() {
		return false;
	}

	/* TODO: pulldown links or something? */
	public function altlinks_text( &$project, $display_filename, $args, $alts ) {
		return '';
	}

	/* TODO: conditional mathjax */
	public function include_latexml_resources( $thing ) {
	}

	public function get_project_file_base_url( $project, $filename, $make=true, $display=null ) {
		if ( is_object($project) ) {
			$project_path = $project->project_name();
			$project = $project->short_directory_name();
		} else {
			$project_path = $project;
		}
		# make sure the files will actually be there.
		global $images_directory;
		$project_images_dir = "$images_directory/$project";
		if ( 0 ) {
		if ( ! file_exists($project_images_dir) and
			# hack: don't link standalone directories bc
			# it's bad to do it before the main project
			strpos( $project, 'standalone' ) === false ) {
			@unlink($project_images_dir);
			#wwLog( "mkdir " . dirname($project_images_dir) );
			mkdir( dirname($project_images_dir), 0777, true );
			#wwLog( "ln -s $project_path $project_images_dir" );
			#symlink( $project_path, $project_images_dir );
			system( "cp -r '$project_path' '$project_images_dir'" );
		}
		}
		if ( ! file_exists( $project_images_dir ) ) {
			mkdir( $project_images_dir, 0777, true );
		}
		copy( "$project_path/$filename", "$project_images_dir/$filename" );
		return "{{ site.baseurl }}/images/$project/$filename";
	}
};

class WMDStorage extends WWStorage {
	public function ok_to_archive_files( $request ) {
		return false;
	}

	public function find_project_by_name( $name, $create=true, $as_of=null ) {
		$name = ProjectDescription::normalized_project_name($name);
		$is_external = $this->is_project_uri($name);
		if ( ! $is_external and
			isset( ProjectDescription::$project_cache ) and
			isset( ProjectDescription::$project_cache[$name] ) ) {
			return ProjectDescription::$project_cache[$name];
		}
		if ( ! $create ) {
			return null;
		}
		$standalone_key = 'Standalone ';
		if ( substr( $name, 0, strlen($standalone_key) ) == $standalone_key ) {
			return new WMDInPlaceStandaloneProjectDescription( substr( $name, strlen( $standalone_key ) ) );
		}
		return new WMDInPlaceProjectDescription( $name );
	}

	public function find_file_content( $filename, &$project, $pagename, $src, $as_of=null ) {
		global $infilename;
		if ( $pagename != $infilename ) {
			return array( 'type' => 'not found' );
		}
		$sftt = $this->find_file_content_on_page( $project, $filename, $pagename, $src, $as_of );
		if ( ! isset( $sftt['text'] ) ) {
			return array( 'type' => 'not found' );
		} else {
			return array(
				'type' => 'tag',
				'page' => $pagename,
				'text' => $sftt['text'],
				'touched' => $sftt['touched'],
			);
		}
	}
};

class WMDInPlaceProjectDescription extends WorkingWikiProjectDescription {
	public function __construct( $directory ) {
		#wwLog( "Construct project: $directory" );
		$this->project_description_page = null;
		$this->project_files = array();
		$this->projectname = $directory;
		$this->uri = preg_replace( '{/$}', '', "file://$directory" );
		$this->options['use-default-makefiles'] = true; // ??
		$this->as_of_revision = null;
		$this->add_GNUmakefile(); // ??
		if ( ! is_array( ProjectDescription::$project_cache ) ) {
			ProjectDescription::$project_cache = array();
		}
		ProjectDescription::$project_cache[ $directory ] = $this;
	}

	public function all_source_file_contents() {
		$asfc = parent::all_source_file_contents();
		#wwLog( "ASFC: " . json_encode( $asfc ) );
		#wwLog( 'project_files is: ' . json_encode( $this->project_files ) );
		return $asfc;
	}

	public function project_page() {
		global $wwContext;
		return $wwContext->wwInterface->currently_parsing_key;
	}

	public function default_locations_for_file( $filename ) {
		return array( $this->project_page() );
	}

	public function fill_pe_request( &$request, $focal, $sync_sf ) {
		parent::fill_pe_request( $request, $focal, $sync_sf );
		$request['projects'][ $this->uri ]['process-in-place'] = true;
	}

	public function short_directory_name() {
		return preg_replace( '{^.*_posts_wmd/}', '', $this->projectname );
	}

	public function env_for_make_jobs() {
		return array();
	}

};

class WMDInPlaceStandaloneProjectDescription extends WMDInPlaceProjectDescription {
	public function __construct( $name ) {
		global $default_project_directory;
		parent::__construct( "$default_project_directory/.workingwiki/standalone/$name" );
	}

	public function is_standalone() {
		return true;
	}
};

if ( php_sapi_name() !== 'cli' ) {
	error_log( "wmd.php called from web server" );
	header( "HTTP/1.0 500 Script execution error" );
?><html><head><title>Working Markdown Execution Error</title></head>
<body><h1>Error: Command-line script called from web server</h1>
<p>The wmd.php script can only be called as a command-line utility.</p>
<p>Usage: <tt>wmd.php &lt;input filename&gt; &lt;output filename&gt;</tt></p>
</body></html>
<?php
	exit -1;
}

# process command line options.

# the options have to come before the input and output filenames
$options = getopt( '', array( 'pre', 'post', 'process-inline-math', 'project-directory:' ) );

$script = $argv[0];
# the last 2 arguments are the in and out filenames
$outfilename = end($argv);
$infilename = prev($argv);

# default value(s)
if (  isset( $options['project-directory'] ) ) {
	$default_project_directory = realpath( $options['project-directory'] );
} else {
	$default_project_directory = realpath( '.' );
}

if ( ! $infilename or ! $outfilename ) {
	file_put_contents('php://stderr', "Usage: $script [--pre] [--post] [--process-inline-math] [--project-directory=XXX] <input filename> <output filename>\n");
	exit -1;
}

$images_directory = preg_replace( '{_posts_wmd/.*$}', 'images', $default_project_directory );

function uncaught_exception_handler( $ex ) {
	echo 'Uncaught exception: ' . $ex->getMessage() . "\n";
	global $wwContext, $infilename;
	echo $wwContext->wwInterface->report_errors_as_text( 'input file', $infilename );
}

set_exception_handler( 'uncaught_exception_handler' );

# set up global ww data

$wwContext = new stdClass();
$wwContext->wwInterface = new WMDInterface;
$wwContext->wwStorage = new WMDStorage;

# note this needs to be an absolute path, not '.'
$project = new WMDInPlaceProjectDescription( $default_project_directory );

$wwContext->wwInterface->default_project_name = $project->project_name();

$tmpfilename = "$infilename.wmd-tmp";

if ( isset( $options['pre'] ) ) {
	# preprocessing:
	
	# create and lock the token-data file

	# get the input text

	$intext = file_get_contents( $infilename );

	# handle math between double-$ signs, if requested
	if ( isset( $options['process-inline-math'] ) ) {
		$intext = $wwContext->wwInterface->replace_inlines( $intext );
	}

	# find locations of all the tags in the text

	$tag_positions = $wwContext->wwStorage->find_project_files_on_page( $infilename, $intext ); 

	# those tags are sorted by project, need them sorted in page order

	# flatten
	$tags = $tag_positions;
	unset( $tags['cache-filled'] );
	$tags = call_user_func_array( 'array_merge', $tags );
	# sort by page order
	usort( $tags, function ( $a, $b ) {
		return $a['position'][0] - $b['position'][0];
	} );

	print json_encode( $tags ) . "\n";

	# replace each tag by a token and output (where?)
	$pretext = '';
	$nextpos = 0;
	foreach ( $tags as $tagdata ) {
		if ( isset( $tagdata['attributes']['project'] ) ) {
			$projectname = $tagdata['attributes']['project'];
		} else {
			$projectname = $wwContext->wwInterface->default_project_name;
		}
		#print json_encode( $tagdata ) . "\n";
		$keeptext = substr( $intext, $nextpos, $tagdata['position'][0] - $nextpos );
		wwLog( "keep text: " . $keeptext );
		$pretext .= $keeptext;
		$tagargs = array(
			'filename' => $tagdata['attributes']['filename'],
			'project' => $projectname,
			// TODO: support various args
		);
		if ( $tagdata['source'] ) {
			$token = $wwContext->wwInterface->source_file_hook(
				'',
				$tagargs,
				null
			);
			wwLog( "token: $token" );
			$pretext .= $token;
		} else {
			$token = $wwContext->wwInterface->project_file_hook(
				'',
				$tagargs,
				null
			);
			wwLog( "token: $token" );
			$pretext .= $token;
		}
		$nextpos = $tagdata['position'][1] + 1;
	}
	$pretext .= substr( $intext, $nextpos );

	if ( ! isset( $options['post'] ) ) {
		file_put_contents( $tmpfilename, $pretext );

		# record the location data
		file_put_contents( "$tmpfilename-data", json_encode( array(
			'uniq_tokens' => $wwContext->wwInterface->uniq_tokens,
			'file_contents' => $tag_positions,
			'expanded_text' => $intext,
		) ) . "\n" );
	}

}

if ( isset( $options['post'] ) ) {

	# postprocessing:

	if ( ! isset( $options['pre'] ) ) {
		# read in text with tokens
		$pretext = file_get_contents( $tmpfilename );

		# read in token data
		$wmd_data = json_decode( file_get_contents( "$tmpfilename-data" ), true );
		$wwContext->wwInterface->uniq_tokens = $wmd_data['uniq_tokens'];
		$now = new DateTime( 'now' );
		$wwContext->wwStorage->pagetext_cache[$infilename] = array(
			'touched' => $now->format( 'YmdHis' ),
			'text' => '', #$wmd_data['expanded_text'],
			'project-files' => $wmd_data['file_contents'],
		);
		$wwContext->wwInterface->currently_parsing_key = $infilename;
	}

	$posttext = $pretext;
	$wwContext->wwInterface->set_page_being_parsed( $infilename ); // for MW_PAGENAME env var
	$wwContext->wwInterface->expand_tokens( $posttext, null, $infilename );

	# write text to output file
	file_put_contents( $outfilename, $posttext );
}
