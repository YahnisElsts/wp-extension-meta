<?php

include 'markdown.php'; //Used to convert readme.txt contents to HTML. 

/**
 * Extract plugin headers and readme.txt data from a plugin's ZIP archive.
 * 
 * Returns an associative array with these keys:
 * 	'headers' - An array of plugin headers. See get_plugin_data() for details.
 *  'readme' - An array of metadata extracted from readme.txt. See parsePluginReadme() for details.
 * 	'pluginFile' - The name of the PHP file where the plugin headers were found; relative to the root directory of the ZIP archive.
 * 
 * The 'readme' key will only be present if the input archive contains a readme.txt file
 * formatted according to WordPress.org readme standards.
 * 
 * @uses parsePluginReadme()
 * @uses get_plugin_data()
 * 
 * @param string $packageFilename The path to the plugin's ZIP package.
 * @param bool $applyMarkdown Whether to transform markup used in readme.txt to HTML. Defaults to false.
 * @return array Associative array containing 'headers', 'readme' and 'pluginFile'. Returns FALSE if the input file is not a valid ZIP archive or doesn't contain a WP plugin.
 */
function analysePluginPackage($packageFilename, $applyMarkdown = false){
	if ( !file_exists($packageFilename) || !is_readable($packageFilename) ){
		return false;
	}
	
	//Open the .zip
	$zip = new ZipArchive();
	if ( $zip->open($packageFilename) !== true ){
		return false; 
	}
	
	//Find and parse the plugin file and readme.txt 
	$header = null;
	$readme = null;
	$pluginFile = null;
	for ( $fileIndex = 0; ($fileIndex < $zip->numFiles) && (empty($readme) || empty($header)); $fileIndex++ ){
		$info = $zip->statIndex($fileIndex);
		$fileName = trim(str_replace('\\', '/', $info['name']), '/'); //Backslashes to slashes, kill leading slashes.
		
		//readme.txt?
		if ( empty($readme) && (strtolower(basename($fileName)) == 'readme.txt') ){
			//Try to parse the readme
			$readme = parsePluginReadme($zip->getFromIndex($fileIndex), $applyMarkdown);
			continue; //Skip the rest of the checks. 
		}
		//Plugin header?
		if ( empty($header) ){
			//Skip directories and empty files
			if ( $info['size'] == 0 ) {
				continue;
			}
			//We're only interested in PHP files
			$extension = end(explode('.', $fileName));
			if ( strtolower($extension) != 'php' ){
				continue;
			}
			//WordPress only looks for plugin files in the top or second level 
			//of the directory tree, so we do the same.
			if ( substr_count($fileName, '/') > 1 ){
				continue;
			}
			//Try to read the header. WP only scans the first 8kiB, so we do the same.
			$fileContents = substr($zip->getFromIndex($fileIndex), 0, 8*1024);
			$header = getPluginHeader($fileContents);
			if ( !empty($header) ){
				$pluginFile = $fileName;
			}
		}
	}
	
	if ( empty($pluginFile) ){
		return false;
	} else {
		return compact('header', 'readme', 'pluginFile');
	}
}

/**
 * Parse a plugin's readme.txt to extract various plugin metadata.
 * 
 * Returns an array with the following fields : 
 * 	'name' - Name of the plugin.
 * 	'contributors' - An array of wordpress.org usernames.
 * 	'donate' - The plugin's donation link.
 * 	'tags' - An array of the plugin's tags.
 * 	'requires' - The minimum version of WordPress that the plugin will run on.
 * 	'tested' - The latest version of WordPress that the plugin has been tested on.
 * 	'stable' - The SVN tag of the latest stable release, or 'trunk'.
 * 	'short_description' - The plugin's "short description".
 * 	'sections' - An associative array of sections present in the readme.txt. Case and formatting of section headers will be preserved.
 * 
 * Be warned that this function does *not* perfectly emulate the way that WordPress.org 
 * parses plugin readme's. In particular, it may mangle certain HTML markup that wp.org 
 * handles correctly.  
 * 
 * @see http://wordpress.org/extend/plugins/about/readme.txt
 * 
 * @param string $readmeTxtContents The contents of a plugin's readme.txt file.
 * @param bool $applyMarkdown Whether to transform Markdown used in readme.txt sections to HTML. Defaults to false.
 * @return array Associative array, or NULL if the input doesn't look like a valid readme.txt file. See above for a list of fields.
 */
function parsePluginReadme($readmeTxtContents, $applyMarkdown = false){
	$readmeTxtContents = trim($readmeTxtContents, " \t\n\r");
	$readme = array(
		'name' => '',
		'contributors' => array(),
		'donate' => '',
		'tags' => array(),
		'requires' => '',
		'tested' => '',
		'stable' => '',
		'short_description' => '',
		'sections' => array(),
	);
	
	//The readme.txt header has a fairly fixed structure, so we can parse it line-by-line
	$lines = explode("\n", $readmeTxtContents);
	//Plugin name is at the very top, e.g. === My Plugin ===
	if ( preg_match('@===\s*(.+?)\s*===@', array_shift($lines), $matches) ){
		$readme['name'] = $matches[1];
	} else {
		return null;
	}
		
	//Then there's a bunch of meta fields formatted as "Field: value"
	$headers = array();
	$readmeHeaders = array(
		'Contributors' => 'contributors',
		'Donate link' => 'donate',
		'Tags' => 'tags',
		'Requires at least' => 'requires',
		'Tested up to' => 'tested',
		'Stable tag' => 'stable', 
	);
	do { //Parse each readme.txt header
		$pieces = explode(':', array_shift($lines), 2);
		if ( array_key_exists($pieces[0], $readmeHeaders) ){
			if ( isset($pieces[1]) ){
				$headers[ $readmeHeaders[$pieces[0]] ] = trim($pieces[1]);
			} else {
				$headers[ $readmeHeaders[$pieces[0]] ] = '';
			}
		}
	} while ( trim($pieces[0]) != '' ); //Until an empty line is encountered
	
	//"Contributors" is a comma-separated list. Convert it to an array.
	if ( !empty($headers['contributors']) ){
		$headers['contributors'] = array_map('trim', explode(',', $headers['contributors']));
	}
	
	//Likewise for "Tags"
	if ( !empty($headers['tags']) ){
		$headers['tags'] = array_map('trim', explode(',', $headers['tags']));
	}
	
	$readme = array_merge($readme, $headers);
	
	//After the headers comes the short description
	$readme['short_description'] = array_shift($lines);
	
	//Finally, a valid readme.txt also contains one or more "sections" identified by "== Section Name =="
	$sections = array();
	$contentBuffer = array();
	$currentSection = '';
	foreach($lines as $line){
		//Is this a section header?
		if ( preg_match('@^\s*==\s+(.+?)\s+==\s*$@m', $line, $matches) ){
			//Flush the content buffer for the previous section, if any
			if ( !empty($currentSection) ){
				$sectionContent = trim(implode("\n", $contentBuffer));
				$sections[$currentSection] = $sectionContent;
			}
			//Start reading a new section
			$currentSection = $matches[1];
			$contentBuffer = array();
		} else {
			//Buffer all section content 
			$contentBuffer[] = $line;
		}
	}
	//Flush the buffer for the last section
	if ( !empty($currentSection) ){
		$sections[$currentSection] = trim(implode("\n", $contentBuffer));
	}
	
	//Apply Markdown to sections 
	if ( $applyMarkdown ){
		$sections = array_map('applyMarkdown', $sections);
	}
	
	//This is only necessary if you intend to later json_encode() the sections.
	//json_encode() may encode certain strings as NULL if they're not in UTF-8.
	$sections = array_map('utf8_encode', $sections);
	
	$readme['sections'] = $sections;
	
	return $readme;
}

/**
 * Extract plugin metadata from a plugin's ZIP file and transform it into a structure
 * compatible with the custom update checker.
 * 
 * This is an utility function that scans the input file (assumed to be a ZIP archive) 
 * to find and parse the plugin's main PHP file and readme.txt file. Plugin metadata from 
 * both files is assembled into an associative array. The structure if this array is 
 * compatible with the format of the metadata file used by the custom plugin update checker 
 * library available at the below URL.
 * 
 * @see http://w-shadow.com/blog/2010/09/02/automatic-updates-for-any-plugin/
 * @see https://spreadsheets.google.com/pub?key=0AqP80E74YcUWdEdETXZLcXhjd2w0cHMwX2U1eDlWTHc&authkey=CK7h9toK&hl=en&single=true&gid=0&output=html
 * 
 * Requires the ZIP extension for PHP.
 * @see http://php.net/manual/en/book.zip.php
 * 
 * @param string|array $packageInfo Either path to a ZIP file containing a WP plugin, or the return value of analysePluginPackage().
 * @return array Associative array  
 */
function getPluginPackageMeta($packageInfo){
	if ( is_string($packageInfo) && file_exists($packageInfo) ){
		$packageInfo = analysePluginPackage($packageInfo, true);
	}
	
	$meta = array();
	
	if ( isset($packageInfo['header']) && !empty($packageInfo['header']) ){
		$mapping = array(
			'Name' => 'name',
		 	'Version' => 'version',
		 	'PluginURI' => 'homepage',
		 	'Author' => 'author',
		 	'AuthorURI' => 'author_homepage',
		);
		foreach($mapping as $headerField => $metaField){
			if ( array_key_exists($headerField, $packageInfo['header']) && !empty($packageInfo['header'][$headerField]) ){
				$meta[$metaField] = $packageInfo['header'][$headerField];
			} 
		}
	}
	
	if ( !empty($packageInfo['readme']) ){
		$mapping = array('requires', 'tested');
		foreach($mapping as $readmeField){
			if ( !empty($packageInfo['readme'][$readmeField]) ){
				$meta[$readmeField] = $packageInfo['readme'][$readmeField];
			} 
		}
		if ( !empty($packageInfo['readme']['sections']) && is_array($packageInfo['readme']['sections']) ){
			foreach($packageInfo['readme']['sections'] as $sectionName => $sectionContent){
				$sectionName = str_replace(' ', '_', strtolower($sectionName));
				$meta['sections'][$sectionName] = $sectionContent;
			}
		}
		
		//Check if we have an upgrade notice for this version
		if ( isset($meta['sections']['upgrade_notice']) && isset($meta['version']) ){
			$regex = "@<h4>\s*" . preg_quote($meta['version']) . "\s*</h4>[^<>]*?<p>(.+?)</p>@i";
			if ( preg_match($regex, $meta['sections']['upgrade_notice'], $matches) ){
				$meta['upgrade_notice'] = trim(strip_tags($matches[1]));
			}
		}
	}
	
	if ( !empty($packageInfo['pluginFile']) ){
		$meta['slug'] = strtolower(basename(dirname($packageInfo['pluginFile'])));
	}
		
	return $meta;
}

/**
 * Transform Markdown markup to HTML.
 * 
 * Tries (in vain) to emulate the transformation that WordPress.org applies to readme.txt files.
 * 
 * @param string $text
 * @return string
 */
function applyMarkdown($text){
	//The WP standard for readme files uses some custom markup, like "= H4 headers ="
	$text = preg_replace('@^\s*=\s*(.+?)\s*=\s*$@m', "<h4>$1</h4>\n", $text);
	return Markdown($text);
}

/**
 * Parse the plugin contents to retrieve plugin's metadata headers.
 * 
 * Adapted from the get_plugin_data() function used by WordPress. Original
 * description follows.
 *
 * The metadata of the plugin's data searches for the following in the plugin's
 * header. All plugin data must be on its own line. For plugin description, it
 * must not have any newlines or only parts of the description will be displayed
 * and the same goes for the plugin data. The below is formatted for printing.
 *
 * <code>
 * /*
 * Plugin Name: Name of Plugin
 * Plugin URI: Link to plugin information
 * Description: Plugin Description
 * Author: Plugin author's name
 * Author URI: Link to the author's web site
 * Version: Must be set in the plugin for WordPress 2.3+
 * Text Domain: Optional. Unique identifier, should be same as the one used in
 *		plugin_text_domain()
 * Domain Path: Optional. Only useful if the translations are located in a
 *		folder above the plugin's base path. For example, if .mo files are
 *		located in the locale folder then Domain Path will be "/locale/" and
 *		must have the first slash. Defaults to the base folder the plugin is
 *		located in.
 * Network: Optional. Specify "Network: true" to require that a plugin is activated
 *		across all sites in an installation. This will prevent a plugin from being
 *		activated on a single site when Multisite is enabled.
 *  * / # Remove the space to close comment
 * </code>
 *
 * Plugin data returned array contains the following:
 *		'Name' - Name of the plugin, must be unique.
 *		'Title' - Title of the plugin and the link to the plugin's web site.
 *		'Description' - Description of what the plugin does and/or notes
 *		from the author.
 *		'Author' - The author's name
 *		'AuthorURI' - The authors web site address.
 *		'Version' - The plugin version number.
 *		'PluginURI' - Plugin web site address.
 *		'TextDomain' - Plugin's text domain for localization.
 *		'DomainPath' - Plugin's relative directory path to .mo files.
 *		'Network' - Boolean. Whether the plugin can only be activated network wide.
 * 
 * If the input string doesn't appear to contain a valid plugin header, the function
 * will return NULL.
 *
 * @link http://trac.wordpress.org/ticket/5651 Previous Optimizations.
 * @link http://trac.wordpress.org/ticket/7372 Further and better Optimizations.
 * @since 1.5.0
 *
 * @param string $file_data Contents of the plugin file
 * @return array|null See above for description.
 */
function getPluginHeader( $file_data ) {

	$default_headers = array(
		'Name' => 'Plugin Name',
		'PluginURI' => 'Plugin URI',
		'Version' => 'Version',
		'Description' => 'Description',
		'Author' => 'Author',
		'AuthorURI' => 'Author URI',
		'TextDomain' => 'Text Domain',
		'DomainPath' => 'Domain Path',
		'Network' => 'Network',
		// Site Wide Only is deprecated in favor of Network.
		'_sitewide' => 'Site Wide Only',
	);

	$plugin_data = getPluginFileData( $file_data, $default_headers );

	// Site Wide Only is the old header for Network
	if ( empty( $plugin_data['Network'] ) && ! empty( $plugin_data['_sitewide'] ) ) {
		$plugin_data['Network'] = $plugin_data['_sitewide'];
	}
	$plugin_data['Network'] = ( 'true' == strtolower( $plugin_data['Network'] ) );
	unset( $plugin_data['_sitewide'] );

	//For backward compatibility by default Title is the same as Name.
	$plugin_data['Title'] = $plugin_data['Name'];
	
	//If it doesn't have a name, it's probably not a plugin.
	if ( empty($plugin_data['Name']) ){
		return null;
	} else {
		return $plugin_data;
	}
}

/**
 * Parse the file contents to retrieve its metadata.
 * 
 * A slightly modified copy of get_file_data() used by WordPress.
 *
 * Searches for metadata for a file, such as a plugin or theme.  Each piece of
 * metadata must be on its own line. For a field spanning multple lines, it
 * must not have any newlines or only parts of it will be displayed.
 *
 * @since 2.9.0
 *
 * @param string $file_data File contents. Can be safely truncated to 8kiB as that's all WP itself scans. 
 * @param array $all_headers The list of headers to search for in the file.
 * @return array
 */
function getPluginFileData( $file_data, $all_headers ) {
	foreach ( $all_headers as $field => $regex ) {
		preg_match( '/' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, ${$field});
		if ( !empty( ${$field} ) )
			${$field} = _cleanup_header_comment( ${$field}[1] );
		else
			${$field} = '';
	}

	$file_data = compact( array_keys( $all_headers ) );

	return $file_data;
}

if ( !function_exists('_cleanup_header_comment') ) {
	/**
	 * Strip close comment and close php tags from file headers used by WP
	 * See http://core.trac.wordpress.org/ticket/8497
	 *
	 * @since 2.8.0
	 *
	 * @param string $str
	 * @return string
	 */
	function _cleanup_header_comment($str) {
		return trim(preg_replace("/\s*(?:\*\/|\?>).*/", '', $str));
	}
}