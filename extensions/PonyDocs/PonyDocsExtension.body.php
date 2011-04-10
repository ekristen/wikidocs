<?php
if( !defined( 'MEDIAWIKI' ))
	die( "PonyDocs MediaWiki Extension" );

/**
 * This is the main class to manage the PonyDocs specific extensions and modifications to the behavior
 * of MediaWiki.  The goal is to include all functional changes inside this extension and the custom
 * PonyDocs skin/theme.  To activate this extension you must modify your LocalSettings.php file and
 * add the following lines:
 * 
 * 	require_once( "$IP/extensions/PonyDocs/PonyDocsExtension.php" ) ;
 * 
 * There are also a set of custom configuration directives TBD.
 * 
 * This file contains the actual body/functions, the above contains the setup for the extension.
 */

/**
 * The primary purpose of this class is as a simple container for any defined hook or extension functions.
 * They will be implemented as static methods.  Currently there is no other use for this class.
 */
class PonyDocsExtension 
{	
	/**
	 * URL mode used on this page load;  0=Normal 1=Aliased URL.
	 *
	 * @var integer Mode (normal or aliased) we are viewing this page in so we can retain that.
	 */
	protected $mURLMode = 0;
	
	/**
	 * Possible modes - NORMAL means normal MW navigation, ALIASED means we got to this page using an aliased
	 * URL and thus must preserve that nomenclature in wiki links on the content and sidebar nav.
	 */
	const URLMODE_NORMAL = 0;
	const URLMODE_ALIASED = 1;

	protected static $speedProcessingEnabled;
	
	/**
	 * Maybe move all hook registration, etc. into this constructor to keep it clean.
	 */
	public function __construct( )
	{
		global $wgScriptPath;
		global $wgHooks, $wgArticlePath;
		
		$this->setPathInfo( );
			
		/**
		 * If we have a title which is an ALIAS of the form:
		 * 		Documentation/<latest|version>/<manual>/<topic>
		 * Then we need to register a hook to do the translation of this to a real topic name.
		 */

		if(preg_match('/^' . str_replace("/", "\/", $wgScriptPath) . '\/Documentation\/((latest|[\w\.]*)\/)?(\w+)\/?$/i', $_SERVER['PATH_INFO'], $match)) {
			$this->mURLMode = PonyDocsExtension::URLMODE_ALIASED;
			return;
		}
		else if( preg_match( '/^' . str_replace("/", "\/", $wgScriptPath) . '\/Documentation\/(.*)\/(.*)\/(.*)$/i', $_SERVER['PATH_INFO'], $match ))
		{					
			$wgHooks['ArticleFromTitle'][] = 'PonyDocsExtension::onArticleFromTitle_New';
			$this->mURLMode = PonyDocsExtension::URLMODE_ALIASED;			
		}			
		
		/**
		 * If we have a title which is an ALIAS of the form:
		 * 	Documentation:<manual>:<topic>
		 * With no version.  Use the latest RELEASED version of the topic.
		 */
		
		else if( preg_match( '/^' . str_replace("/", "\/", $wgScriptPath) . '\/Documentation:([^:]+):([^:]+)$/i', $_SERVER['PATH_INFO'], $match ))
		{			
			$wgHooks['ArticleFromTitle'][] = 'PonyDocsExtension::onArticleFromTitle_NoVersion';
		}

	}



	/**
	 * This sets $_SERVER[PATH_INFO] based on the article path and request URI if PATH_INFO is not set.  You should
	 * STILL set $wgUsePathInfo to false in your settings if PATH_INFO is not set by your web server (PBR).  This
	 * is just a quick fix to make URL aliasing work properly in those cases.
	 *
	 * @return string
	 */
	public function setPathInfo( )
    {
        global $wgArticlePath;

        if( !isset( $_SERVER['PATH_INFO'] ))
        {
            $p = str_replace( '$1', '', $wgArticlePath );
			if(!empty($_SERVER['REQUEST_URI'])) {
				$_SERVER['PATH_INFO'] = substr( $_SERVER['REQUEST_URI'], strpos( $_SERVER['REQUEST_URI'], $p ));
			}
			else {
				$_SERVER['PATH_INFO'] = $wgArticlePath;
			}
        }

        return $_SERVER['PATH_INFO'];
    }
	
	/**
	 * Return the URL mode (aliased or normal).
	 *
	 * @return integer
	 */
	public function getURLMode( )
	{
		return $this->mURLMode;	
	}
	
	/**
	 * Method used to take a Title object that is ALIASED and extract the real topic it refers to.  These are of
	 * the form:
	 * 
	 * 	/Documentation:Manual/(latest|version)/Topic
	 * 
	 * The 'latest' keyword will return the version of the topic tagged to the most recent version available.  If
	 * a specific version is specified it will look for the given topic tagged wtih that version.  In any case
	 * where the topic is not found, there are no versions for it, or the requested version is not found (etc.)
	 * it redirects to the /Documentation default URL.
	 *
	 * @param Title $reTitle
	 * @return mixed String (title referenced) or false on failure.
	 */
	static public function RewriteTitle( Title & $reTitle )
	{ 
		global $wgArticlePath, $wgTitle, $wgArticle;
		
		$dbr = wfGetDB( DB_SLAVE );
		
		/**
		 * We only care about Documentation namespace for rewrites and they must contain a slash, so scan for it.
		 */
		if( !preg_match( '/^Documentation:(.*)\/(.*)\/(.*)$/i', $reTitle->__toString( ), $matches ))
			return false;
			
		$defaultRedirect = str_replace( '$1', 'Documentation', $wgArticlePath );			
		
		/**
		 * At this point $matches contains:
		 * 	0= Full title.
		 *  1= Manual name (short name).
		 *  2= Version OR 'latest' as a string.
		 *  3= Wiki topic name.
		 */		
		PonyDocsVersion::LoadVersions();
		$versionName = $matches[2];
		$version = '';
		
		if( !strcasecmp( 'latest', $versionName ))
		{	
			/**
			 * This will be a DESCENDING mapping of version name to PonyDocsVersion object and will ONLY contain the
			 * versions available to the current user (i.e. LoadVersions() only loads the ones permitted).
			 */
			$versionList = array_reverse( PonyDocsVersion::GetVersions( true ));
			$versionNameList = array( );
			foreach( $versionList as $pV )
				$versionNameList[] = $pV->getName( );						
			
			/**
			 * Now get a list of version names to which the current topic is mapped in DESCENDING order as well
			 * from the 'categorylinks' table.
			 *
			 * DB can't do descending order here, it depends on the order defined in versions page!  So we have to
			 * do some magic sorting below.	
			 */
			$res = $dbr->select( 'categorylinks', 'cl_to', 
								 "LOWER(cast(cl_sortkey AS CHAR)) LIKE 'documentation:" . $dbr->strencode( strtolower( $matches[1] . ':' . $matches[3] )) . ":%'",
								 __METHOD__ );
								 
			if( !$res->numRows( ))
			{
				/**
				 * What happened here is we requested a topic that does not exist or is not linked to any version.
				 * Perhaps setup a default redirect, Main_Page or something?
				 */
				Header( "Location: " . $defaultRedirect );
				exit( 0 );
			}
			
			/**
			 * Based on our list, get the PonyDocsVersion for each version tag and store in an array.  Then pass this array
			 * to our custom sort function via usort() -- the ending result is a sorted list in $existingVersions, with the
			 * LATEST version at the front.
			 */
			$existingVersions = array( );
			while( $row = $dbr->fetchObject( $res ))
			{		
				if( preg_match( '/^V:(.*)/i', $row->cl_to, $vmatch ))
				{
					$pVersion = PonyDocsVersion::GetVersionByName( $vmatch[1] );
					if( $pVersion && !in_array( $pVersion, $existingVersions ))
						$existingVersions[] = $pVersion;
				}
			}
				
			usort( $existingVersions, 'PonyDocs_versionCmp' );
			$existingVersions = array_reverse( $existingVersions );

			
			/**
			 * Now we need to filter out any versions which this user has no access to.  The easiest way is to loop through
		 	 * our resulting $existingVersions and see if each is in_array( $versionNameList );  if its NOT, continue looping.
			 * Once we hit one, redirect.  if we exhaust our list, go to the main page or something.
			 */
			foreach( $existingVersions as $pV )
			{
				if( in_array( $pV->getName( ), $versionNameList ))
				{
					/**
					 * Look up topic name and redirect to URL.
					 */
					
					$res = $dbr->select( 'categorylinks', 'cl_sortkey', 
										array( 	"LOWER(cast(cl_sortkey AS CHAR)) LIKE 'documentation:" . $dbr->strencode( strtolower( $matches[1] . ':' . $matches[3] )) . ":%'",
												"cl_to = 'V:" . $pV->getName( ) . "'" ), __METHOD__ );					

					if( !$res->numRows( ))
					{
						Header( "Location: " . $defaultRedirect );
						exit( 0 );
					}

					$row = $dbr->fetchObject( $res );
					return $row->cl_sortkey;					
				}
			}
			
			/**
			 * Invalid redirect -- go to Main_Page or something.
			 */
			Header( "Location: " . $defaultRedirect );
			exit( 0 );			
		}
		else
		{
			/**
			 * Ensure version specified in aliased URL is a valid version -- if it is not we just need to do our default
			 * redirect here.
			 */
			$version = PonyDocsVersion::GetVersionByName( $versionName );	
			if( !$version )
			{
				Header( "Location: " . $defaultRedirect );
				exit( 0 );
			}				
						
			/**
			 * Look up the TOPIC in the categorylinks and find the one which is tagged with the version supplied.  This
			 * is the URL to redirect to.  
			 */
			$res = $dbr->select( 'categorylinks', 'cl_sortkey', 
					array( 	"LOWER(cast(cl_sortkey AS CHAR)) LIKE 'documentation:" . strtolower( $matches[1] ) . ':' . strtolower( $matches[3] ) . ":%'",
							"cl_to = 'V:" . $version->getName( ) . "'" ), __METHOD__ );
				
			if( !$res->numRows( ))
			{
				/**
				 * Handle invalid redirects?
				 */	
				Header( "Location: " . $defaultRedirect );
				exit( 0 );		
			}
			
			$row = $dbr->fetchObject( $res );					
			return $row->cl_sortkey;
		}
		
		return false;
	}
	
	/**
	 * Hook for ArticleFromTitle.  Takes our title object, rewrites it with the RewriteTitle() method, then creates an instance of
	 * our custom Article sub-class 'PonyDocsAliasArticle' and stores it in the passed reference.
	 *
	 * @param Title $title
	 * @param Article $article
	 * @return boolean 
	 */
	static public function onArticleFromTitle( &$title, &$article )
	{
		$newTitleStr = PonyDocsExtension::RewriteTitle( $title );

		if( $newTitleStr !== false )
		{	
			$title = Title::newFromText( $newTitleStr );
			
			$article = new PonyDocsAliasArticle( $title );
			$article->loadContent( );

			if( !$article->exists( ))
				$article = null;	
		}
		
		return true;		
	}
	
	static public function onArticleFromTitle_NoVersion( &$title, &$article )
	{
		global $wgArticlePath;
		
		$defaultRedirect = str_replace( '$1', 'Documentation', $wgArticlePath );
		
		$dbr = wfGetDB( DB_SLAVE );
		
		$res = $dbr->select( 'categorylinks', array( 'cl_sortkey', 'cl_to' ), 
				"LOWER(cast(cl_sortkey AS CHAR)) LIKE '" . $dbr->strencode( strtolower( $title->__toString( ))) . ":%'", __METHOD__ );
		
		if( !$res->numRows( ))
		{
			Header( "Location: " . $defaultRedirect );
			exit( 0 );
		}

		/**
		 * First create a list of versions to which the current user has access to.
		 */
		
		$versionList = array_reverse( PonyDocsVersion::GetVersions( true ));
		$versionNameList = array( );
		foreach( $versionList as $pV )
			$versionNameList[] = $pV->getName( );						
		
		/**
		 * Create a list of existing versions for this topic.  The list contains PonyDocsVersion instances.  Only store
		 * UNIQUE instances and valid pointers.  Once done, sort them so that the LATEST version is at the front of
		 * the list (index 0).
		 */
		$existingVersions = array( );
		while( $row = $dbr->fetchObject( $res ))
		{
			if( preg_match( '/^V:(.*)/i', $row->cl_to, $vmatch ))
			{
				$pVersion = PonyDocsVersion::GetVersionByName( $vmatch[1] );
				if( $pVersion && !in_array( $pVersion, $existingVersions ))
					$existingVersions[] = $pVersion;
			}
		}
		
		usort( $existingVersions, 'PonyDocs_versionCmp' );
		$existingVersions = array_reverse( $existingVersions );
	
		/**
		 * Now filter out versions the user does not have access to from the top;  once we find the version for this topic
		 * to which the user has access, create our Article object and replace our title (to not redirect) and return true.
		 */
		foreach( $existingVersions as $pV )
		{
			if( in_array( $pV->getName( ), $versionNameList ))
			{
				/**
				 * Look up topic name and redirect to URL.
				 */
				
				$res = $dbr->select( 'categorylinks', 'cl_sortkey', 
									array( 	"LOWER(cast(cl_sortkey AS CHAR)) LIKE '" . $dbr->strencode( strtolower( $title->__toString( ))) . ":%'",
											"cl_to = 'V:" . $pV->getName( ) . "'" ), __METHOD__ );					

				if( !$res->numRows( ))
				{
					Header( "Location: " . $defaultRedirect );
					exit( 0 );
				}

				$row = $dbr->fetchObject( $res );
				$title = Title::newFromText( $row->cl_sortkey );
				
				$article = new PonyDocsAliasArticle( $title );
				$article->loadContent( );

				if( !$article->exists( ))
					$article = null;	
		
				return true;						
			}
		}
		
		/**
		 * Invalid redirect -- go to Main_Page or something.
		 */
		Header( "Location: " . $defaultRedirect );
		exit( 0 );			
	}
	
	static public function onArticleFromTitle_New( &$title, &$article )
	{ 
		global $wgScriptPath;
		global $wgArticlePath, $wgTitle, $wgArticle, $wgOut, $wgHooks;
		
		$dbr = wfGetDB( DB_SLAVE );
		
		/**
		 * We only care about Documentation namespace for rewrites and they must contain a slash, so scan for it.
		 * $matches[1] = latest|version
		 * $matches[2] = manual
		 * $matches[3] = topic
		 */
		if( !preg_match( '/^Documentation\/(.*)\/(.*)\/(.*)$/i', $title->__toString( ), $matches ))
			return false;
			
		$defaultRedirect = str_replace( '$1', 'Documentation', $wgArticlePath );			

		/**
		 * At this point $matches contains:
		 * 	0= Full title.
		 *  1= Manual name (short name).
		 *  2= Version OR 'latest' as a string.
		 *  3= Wiki topic name.
		 */		
		PonyDocsVersion::LoadVersions();
		$versionName = $matches[1];
		$version = '';
		
		if( !strcasecmp( 'latest', $versionName ))
		{	
			/**
			 * This will be a DESCENDING mapping of version name to PonyDocsVersion object and will ONLY contain the
			 * versions available to the current user (i.e. LoadVersions() only loads the ones permitted).
			 */
			$versionList = array_reverse( PonyDocsVersion::GetReleasedVersions( true ));
			$versionNameList = array( );
			foreach( $versionList as $pV )
				$versionNameList[] = $pV->getName( );						
			
			/**
			 * Now get a list of version names to which the current topic is mapped in DESCENDING order as well
			 * from the 'categorylinks' table.
			 *
			 * DB can't do descending order here, it depends on the order defined in versions page!  So we have to
			 * do some magic sorting below.	
			 */

			$res = $dbr->select( 'categorylinks', 'cl_to', 
								 "LOWER(cast(cl_sortkey AS CHAR)) LIKE 'documentation:" . $dbr->strencode( strtolower( $matches[2] . ':' . $matches[3] )) . ":%'",
								 __METHOD__ );

								 
			if( !$res->numRows( ))
			{
				/**
				 * What happened here is we requested a topic that does not exist or is not linked to any version.
				 * Perhaps setup a default redirect, Main_Page or something?
				 */
				Header( "Location: " . $defaultRedirect );
				exit( 0 );
			}
			
			/**
			 * Based on our list, get the PonyDocsVersion for each version tag and store in an array.  Then pass this array
			 * to our custom sort function via usort() -- the ending result is a sorted list in $existingVersions, with the
			 * LATEST version at the front.
			 * 
			 * @FIXME:  GetVersionByName is missing some versions?
			 */
			$existingVersions = array( );
			while( $row = $dbr->fetchObject( $res ))
			{						
				if( preg_match( '/^V:(.*)/i', $row->cl_to, $vmatch ))
				{
					$pVersion = PonyDocsVersion::GetVersionByName( $vmatch[1] );					
					if( $pVersion && !in_array( $pVersion, $existingVersions ))
						$existingVersions[] = $pVersion;
				}
			}
 
			usort( $existingVersions, "PonyDocs_versionCmp" );
			$existingVersions = array_reverse( $existingVersions );			

			// Okay, iterate through existingVersions.  If we can't see that 
			// any of them belong to our latest released version, redirect to 
			// our latest handler.
			$latestReleasedVersion = PonyDocsVersion::GetLatestReleasedVersion()->getName();
			$found = false;
			foreach($existingVersions as $docVersion) {
				if($docVersion->getName() == $latestReleasedVersion) {
					$found = true;
					break;
				}
			}
			if(!$found) {
				header("Location: " . $wgScriptPath . "/Special:PonyDocsLatestDoc?t=$title", true, 301);
				exit(0);
			}

			/**
			 * Now we need to filter out any versions which this user has no access to.  The easiest way is to loop through
		 	 * our resulting $existingVersions and see if each is in_array( $versionNameList );  if its NOT, continue looping.
			 * Once we hit one, redirect.  if we exhaust our list, go to the main page or something.
			 */
			foreach( $existingVersions as $pV )
			{
				if( in_array( $pV->getName( ), $versionNameList ))
				{
					/**
					 * Look up topic name and redirect to URL.
					 */
					
					$res = $dbr->select( 'categorylinks', 'cl_sortkey', 
										array( 	"LOWER(cast(cl_sortkey AS CHAR)) LIKE 'documentation:" . $dbr->strencode( strtolower( $matches[2] . ':' . $matches[3] )) . ":%'",
												"cl_to = 'V:" . $pV->getName( ) . "'" ), __METHOD__ );					

					if( !$res->numRows( ))
					{
						Header( "Location: " . $defaultRedirect );
						exit( 0 );
					}

					$row = $dbr->fetchObject( $res );
					$title = Title::newFromText( $row->cl_sortkey );
				
					$article = new PonyDocsAliasArticle( $title );
					$article->loadContent( );
					
					//die( $pV->getName( ));
					
					PonyDocsVersion::SetSelectedVersion( $pV->getName( ));					
	
					if( !$article->exists( ))
						$article = null;	
			
					return true;						
				}
			}
			
			/**
			 * Invalid redirect -- go to Main_Page or something.
			 */
			Header( "Location: " . $defaultRedirect );
			exit( 0 );			
		}
		else
		{
			/**
			 * Ensure version specified in aliased URL is a valid version -- if it is not we just need to do our default
			 * redirect here.
			 */


			$version = PonyDocsVersion::GetVersionByName( $versionName );	
			if( !$version )
			{
				Header( "Location: " . $defaultRedirect );
				exit( 0 );
			}				
						
			/**
			 * Look up the TOPIC in the categorylinks and find the one which is tagged with the version supplied.  This
			 * is the URL to redirect to.  
			 */
			$res = $dbr->select( 'categorylinks', 'cl_sortkey', 
					array( 	"LOWER(cast(cl_sortkey AS CHAR)) LIKE 'documentation:" . strtolower( $matches[2] ) . ':' . strtolower( $matches[3] ) . ":%'",
							"cl_to = 'V:" . $version->getName( ) . "'" ), __METHOD__ );
				
			if( !$res->numRows( ))
			{
				/**
				 * Handle invalid redirects?
				 */	
				$wgHooks['BeforePageDisplay'][] = "PonyDocsExtension::handle404";
				return false;
			}
			
			$row = $dbr->fetchObject( $res );		
			$title = Title::newFromText( $row->cl_sortkey );
			
			PonyDocsVersion::SetSelectedVersion( $versionName );
			
			$article = new PonyDocsAliasArticle( $title );
			$article->loadContent( );

			if( !$article->exists( ))
				$article = null;	
		
			return true;				
			
		}
		
		return false;
	}	
	
	/**
	 * This is an ArticleSave hook to ensure manual TOCs are proper -- meaning, no duplicate TOCs.  We should then regenerate
	 * the TOC cache (PonyDocsTOC) for this TOC, either here or on an AFTER ArticleSave sort of hook.
	 * 
	 * @param Article $article
	 * @param User $user
	 * @param string $text
	 * @param string $summary
	 * @param bool $minor
	 * @param unknown_type $watch
	 * @param unknown_type $sectionanchor
	 * @param unknown_type $flags
	 */
	static public function onArticleSave_CheckTOC( &$article, &$user, &$text, &$summary, $minor, $watch, $sectionanchor, &$flags )
	{

		// Dangerous.  Only set the flag if you know that you should be skipping 
		// this processing.  Currently used for branch/inherit.
		if(PonyDocsExtension::isSpeedProcessingEnabled()) {
			return true;
		}


		$title = $article->getTitle( );
		
		/**
		 * For manual TOCs we need to ensure the same topic is not listed twice.  If it is we output an error and return
	  	 * false.  This is not working, its like it doesn't recognize when I fix the edit box and submit again?  It still
		 * captures and parses the old input =/
		 */
		$matches = array( );
		
		if( preg_match( '/Documentation:(.*)TOC(.*)/i', $title->__toString( ), $match ))
		{
			$dbr = wfGetDB( DB_MASTER );			
			$topics = array( );
			
			/**
		 	 * Detect duplicate topic names.
			 */
			if( preg_match_all( '/{{#topic\s*:\s*(.*)}}/', $text, $matches, PREG_SET_ORDER ))
			{				
				foreach( $matches as $m )
				{									
					/*
					if( in_array( $m[1], $topics ))												
						return "You have one or more duplicate topics in your Table of Contents.  Please fix then save again.";					
					*/
					$topics[] = $m[1];					
				}				
			}
		
			/**
			 * Create any topics which do not already exist in the saved TOC.
			 */ 
			$pManual = PonyDocsManual::GetManualByShortName( $match[1] );
			$pManualTopic = new PonyDocsTopic( $article );
			
			$manVersionList = $pManualTopic->getVersions( );
			if( !sizeof( $manVersionList ))
			{		
				return true;
			}

			// Clear all TOC cache entries for each version.
			if($pManual) {
				foreach($manVersionList as $version) {
					PonyDocsTOC::clearTOCCache($pManual, $version);
				}
			}

			$earliestVersion = PonyDocsVersion::findEarliest( $manVersionList );	
			
			foreach( $matches as $m )
			{	
				$wikiTopic = preg_replace( '/([^' . str_replace( ' ', '', Title::legalChars( )) . '])/', '', $m[1] );
				$wikiPath = 'Documentation:' . $match[1] . ':' . $wikiTopic;
				
				$versionIn = array( );
				foreach( $manVersionList as $pV )
					$versionIn[] = $pV->getName( );
					
				$res = $dbr->select( 'categorylinks', 'cl_sortkey',
					array( 	"LOWER(cl_sortkey) LIKE 'documentation:" . $dbr->strencode( strtolower( $match[1] . ":" . $wikiTopic )) . ":%'",
							"cl_to IN ('V:" . implode( "','V:", $versionIn ) . "')" ), __METHOD__ );
				
				$topicName = '';
				if( !$res->numRows( ))
				{	
					/**
					 * No match -- so this is a "new" topic.  Set name and create.
					 */
					$topicName = 'Documentation:' . $match[1]. ':' . $wikiTopic . ':' . $earliestVersion->getName( );
					//die( $topicName );
					
					$topicArticle = new Article( Title::newFromText( $topicName ));
					if( !$topicArticle->exists( ))
					{		
						$content = 	"= " . $m[1] . "=\n\n" ;
						foreach( $manVersionList as $pVersion )
							$content .= '[[Category:V:' . $pVersion->getName( ) . ']]';
						
						$topicArticle->doEdit( $content, '', EDIT_NEW );
					}		
				}
			}
		}
		return true;
	}

	/**
	 * Hook called AFTER an article was SUCCESSFULLY saved (meaning a new revision was created).  This specific hook is used
	 * to regenerate the manual TOC cache for this manual.
	 *
	 * @param Article $article
	 * @param User $user
	 * @param string $text
	 * @param string $summary
	 * @param bool $minor
	 * @param unknown_type $watch NOT USED AS OF 1.8
	 * @param unknown_type $sectionanchor NOT USED AS OF 1.8
	 * @param unknown_type $flags Bitfield.
	 * @param Revision $revision
	 */
	static public function onArticleSaveComplete_UpdateTOCCache( &$article, &$user, &$text, &$summary, $minor, $watch, $sectionanchor, &$flags, $revision )
	{
		$title = $article->getTitle( );
		
		if( false && preg_match( '/Documentation:(.*)TOC(.*)/i', $title->__toString( ), $match ))
		{
			/**
			 * Extract our version and manual objects.  From it build the cache key then REMOVE IT.  THEN, create our PonyDocsTOC
			 * and call loadContent(), which should rehash the TOC data and store it into the key.
			 */
			$cache = PonyDocsCache::getInstance( );
			
			$pManual = PonyDocsManual::GetManualByShortName( $match[1] );
			$pVersion = PonyDocsVersion::GetVersionByName( $match[2] );

			$tocKey = PonyDocsTOC . '_' . $pManual->getShortName( ) . '_' . $pVersion->getName( );
			
			$cache->remove( $tocKey );

			// Clear any PDF for this manual
			PonyDocsPdfBook::removeCachedFile($pManual, $pVersion);

			$pTOC = new PonyDocsTOC( $pManual, $pVersion );
			$pTOC->loadContent( );			
		}
		
		return true;
	}
	
	/**
	 * Hook for 'ArticleSave' which is called when a request to save an article is made BUT BEFORE anything 
	 * is done.  We trap these for certain special circumstances and perform additional processing.  Otherwise
	 * we simply fall through and allow normal processing to occur.  It returns true on success and then falls
	 * through to other hooks, a string on error, and false on success but skips additional processing.
	 * 
	 * These include:
	 * 	
	 *	- If a page is saved in the Documentation namespace and is tagged for a version that another form of
	 *	  the SAME topic has already been tagged with, it needs to generate a confirmation page which offers
	 *	  to strip the version tag from the older/other topic, via AJAX.  See onUnknownAction for the handling
	 *	  of the AJAX call 'ajax-removetags'.
	 *
	 *  - We need to ensure any 'Category' tags present reference a defined version;  else we produce an error.
	 *
	 * @param Article $article
	 * @param User $user
	 * @param string $text
	 * @param string $summary
	 * @param bool $minor
	 * @param unknown_type $watch
	 * @param unknown_type $sectionanchor
	 * @param unknown_type $flags
	 */
	static public function onArticleSave( &$article, &$user, &$text, &$summary, $minor, $watch, $sectionanchor, &$flags )
	{		
		global $wgRequest, $wgOut, $wgArticlePath, $wgRequest, $wgScriptPath, $wgHooks;

		$editPonyDocsVersion = $wgRequest->getVal("ponydocsversion");
		if($editPonyDocsVersion != null) {
			PonyDocsVersion::SetSelectedVersion($editPonyDocsVersion);	
		}

		// Dangerous.  Only set the flag if you know that you should be skipping 
		// this processing.  Currently used for branch/inherit.
		if(PonyDocsExtension::isSpeedProcessingEnabled()) {
			return true;
		}

		// We're going to add a entry into the error_log to dictate who edited, 
		// if they're an employee, and what topic they modified.
		$groups = $user->getGroups();
		$isEmployee = false;
		if(in_array( PONYDOCS_EMPLOYEE_GROUP, $groups )) {
  			$isEmployee = true;
		}
		error_log("INFO [wikiedit] username=\"" . $user->getName() . "\" usertype=\"" . ($isEmployee ? 'employee' : 'nonemployee') . "\" url=\"" . $article->getTitle()->getFullURL() . "\"");		

		$title = $article->getTitle( );
		if( !preg_match( '/Documentation:/', $title->__toString( )))
			return true;

		// check if this is a TOC page.  If so, the navigation cache should 
		// expire.
		// Unfortunately, we can't do this on a per version basis, because 
		// someone could modify a version TOC directly without modifying their 
		// selected version.
		if(preg_match("/^Documentation:(.*)TOC(.*)/i", $title->__toString( ))) {
			PonyDocsExtension::ClearNavCache();
		}


		
		
		$dbr = wfGetDB( DB_SLAVE );
		
		/**
		 * Check to see if we have any version tags -- if we don't we don't care about this and can skip and return true.		 
		 */		
		if( preg_match_all( '/\[\[Category:v:([A-Za-z0-9 _.-]*)\]\]/is', $text, $matches, PREG_SET_ORDER ))
		{			


			$categories = array( );
			foreach( $matches as $m )
				$categories[] = $m[1];
				
			/**
			 * Ensure ALL Category tags present reference defined versions.
			 */			
			foreach( $categories as $c )
			{
				$v = PonyDocsVersion::GetVersionByName( $c );
				if( !$v )
				{						
					$wgOut->addHTML( '<h3>The version <span style="color:red;">' . $c . '</span> does not exist.  Please update version list if you wish to use it.</h3>' );					
					return false;
				}

			}


			
			/**
			 * Now let's find out topic name.  From that we can look in categorylinks for all tags for this topic, regardless
			 * of topic name (i.e. Documentation:User:HowToFoo:%).  We need to restrict this so that we do not query ourselves
			 * (our own topic name) and we need to check for 'cl_to' to be in $categories generated above.  If we get 1 or more
			 * hits then we need to inject a form element (or something) and return FALSE.
			 *
			 * @FIXME:  Should also work on TOC management pages!
			 */
			
			$q = '';
			
			if( preg_match( '/Documentation:(.*):(.*):(.*)/', $title->__toString( ), $titleMatch ))
			{
				$q =	"SELECT cl_to, cl_sortkey FROM categorylinks " .
						"WHERE LOWER(cl_sortkey) LIKE 'documentation:" . $dbr->strencode( strtolower( $titleMatch[1] . ':' . $titleMatch[2] )) . ":%' " .
						"AND LOWER(cl_sortkey) <> 'documentation:" . $dbr->strencode( strtolower( $titleMatch[1] . ':' . $titleMatch[2] . ':' . $titleMatch[3] )) . "' " .
						"AND cl_to IN ('V:" . implode( "','V:", $categories ) . "')";				
			}
			else if( preg_match( '/Documentation:(.*)TOC(.*)/', $title->__toString( ), $titleMatch ))
			{
				$q =	"SELECT cl_to, cl_sortkey FROM categorylinks " .
						"WHERE LOWER(cl_sortkey) LIKE 'documentation:" . $dbr->strencode( strtolower( $titleMatch[1] . 'TOC' )) . "%' " .
						"AND LOWER(cl_sortkey) <> 'documentation:" . $dbr->strencode( strtolower( $titleMatch[1] . 'TOC' . $titleMatch[2] )) . "' " .
						"AND cl_to IN ('V:" . implode( "','V:", $categories ) . "')";	
			}
			else
			{
				return true;
			}
			$res = $dbr->query( $q, __METHOD__ );
			if( !$res->numRows( )) {
				return true;
			}
			$duplicateVersions = array( );
			$topic = '';

			while( $row = $dbr->fetchObject( $res ))
			{
				if( preg_match( '/^V:(.*)/i', $row->cl_to, $vmatch ))
				{
					$topic = $row->cl_sortkey;				
					$duplicateVersions[] = $vmatch[1];
				}
			}


			//echo '<pre>'; print_r( $duplicateVersions ); die();
			
			/**
			 * Produce a warning message with a link to the topic which has the duplicates.  This will list the topic which
			 * is already tagged to these versions and the versions tagged for.  It will also contain a simple link to click,
			 * which uses AJAX to call the special action 'removetags' (see onUnknownAction method) and passes it a string of
			 * colon delimited versions.  This will strip the version tags from the topic and then hide the output message
			 * (the warning) and allow the user to submit again.
			 *
			 * @FIXME:  Update this to use the stuff from PonyDocsAjax.php to be cleaner.
			 */
			
			$msg =	<<<HEREDOC
				
				function ajaxRemoveVersions( url ) {
					var xmlHttp;
					
					try {
						xmlHttp = new XMLHttpRequest();
					}
					catch( e ) {
						try {
							xmlHttp = new ActiveXObject( "Msxml2.XMLHTTP" );
						}
						catch( e ) {
							try {
								xmlHttp = new ActiveXObject( "Microsoft.XMLHTTP" );
							}
							catch( e )	 {
								alert( "No AJAX support." );
								return false;
							}
						}
					}
				
					xmlHttp.onreadystatechange = function() {						
				    		if( xmlHttp.readyState == 4 ) {			    							      			
				      			if( document.getElementById ) {
				      				document.getElementById("version-warning").style.display="none";
				      				document.getElementById("version-warning-done").style.display="block";
				      			} 				      				
				      		}
				    	}
	
					xmlHttp.open( "GET", url, true );
					xmlHttp.send(null);
				}
HEREDOC;
			
			$wgOut->addInLineScript( $msg );

			$msg = '<div id="version-warning"><h3>There\'s already a topic with the same name as this topic.' .
					'That other topic is already tagged with version(s): ' . implode(',', $duplicateVersions) .  '.' .
					' Click <a href="#" onClick="ajaxRemoveVersions(\'' . $wgScriptPath . '/index.php?title=' . $topic . '&action=ajax-removetags&versions=' . implode(',', $duplicateVersions) . '\');">here</a> to remove the version tag(s) from the other topic and use this one instead.' .
					' Here\'s a link to that topic so you can decide which one you want to use: ' .
					'<a href="' . str_replace('$1', $topic, $wgArticlePath) . '">' . $topic . '</a></div>' . 
			
					'<div id="version-warning-done" style="display:none;"><h4>Version tags removed from article ' .
					'<a href="' . str_replace( '$1', $topic, $wgArticlePath ) . '">' . $topic . '</a> ' .
					'- Submit to save changes to this topic.</h4></div>';
			
			$wgOut->addHTML( $msg );

			/**
			 * No idea why but things were interfering and causing this to not work.
			 */
			$wgHooks['ArticleSave'] = array( );
			
			return false;
		}

		return true;
	}

	/**
	 * This is used to scan a topic in the Documentation namespace when saved for wiki links, and when it finds them, it should
	 * create the topic in the namespace (if it does not exist) then set the H1 to the alternate text (if supplied) and then
	 * tag it for the versions of the currently being viewed page?  We can assume Documentation namespace.
	 * 
	 * 	[[SomeTopic|My Topic Here]] <- Creates Documentation:<currentManual>:SomeTopic:<selectedVersion> and sets H1.
	 *	[[Dev:HowToFoo|How To Foo]] <- Creates Dev:HowToFoo and sets H1.
	 *  [[Documentation:User:SomeTopic|Some Topic]] <- To create link to another manual, will use selected version.
	 *	[[Documentation:User:SomeTopic:1.0|Topic]] <- Specific title in another manual.
	 *	[[:Main_Page|Main Page]] <- Link to a page in the global namespace.
	 *
	 * When creating the link in Documentation namespace, it uses the CURRENT MANUAL being viewed.. and the selected version?
	 */
	static public function onArticleSave_AutoLinks( &$article, &$user, &$text, &$summary, $minor, $watch, $sectionanchor, &$flags )
	{		
		global $wgRequest, $wgOut, $wgArticlePath, $wgRequest, $wgScriptPath;

		// Dangerous.  Only set the flag if you know that you should be skipping 
		// this processing.  Currently used for branch/inherit.
		if(PonyDocsExtension::isSpeedProcessingEnabled()) {
			return true;
		}


		$title = $article->getTitle( );
		if( !preg_match( '/Documentation:/', $title->__toString( )))
			return true;
		

		$dbr = wfGetDB( DB_SLAVE );
		
		if( preg_match_all( "/\[\[([" . Title::legalChars( ) . "]*)([|]?(.*))\]\]/", $text, $matches, PREG_SET_ORDER ))
		//if( preg_match_all( "/\[\[([A-Za-z0-9,:._ -]*)([|]?([A-Za-z0-9,:._?#!@$+= -]*))\]\]/", $text, $matches, PREG_SET_ORDER ))
		{
			/**
			 * $match[1] = Wiki Link
			 * $match[3] = Alternate Text
			 */				
			foreach( $matches as $match )
			{
				/**
				 * Forms which can exist are as such:
				 * [[TopicNameOnly]]	`		Links to Documentation:<currentManual>:<topicName>:<selectedVersion>
				 * [[Documentation:Inst:Setup]]	Links to a different manual from a manual (uses selectedVersion).
				 * [[Dev:SomeTopicName]]		Links to another namespace and topic explicitly.				
				 * So we first need to detect the use of a namespace.
				 */
				if( strpos( $match[1], ':' ) !== false )
				{
					$pieces = split( ':', $match[1] );
					
					if( !strcasecmp( $pieces[0], 'Documentation' ))
					{
						/**
						 * Handle [[Documentation:User:HowToFoo]] referencing selected version -AND-
						 * [[Documentation:User:HowToFoo:1.0]] as an explicit link to a page.
						 */
						if( sizeof( $pieces ) == 3 )
						{							
							$version = PonyDocsVersion::GetSelectedVersion( );

							/**
							 * Does this topic exist?  Look for a topic with this name tagged for the current version.
							 * If nothing is found, we create a new article.
							 */
							$sqlMatch = 'Documentation:' . $pieces[1] . ':' . $pieces[2];
							$res = $dbr->select( 	'categorylinks', 'cl_sortkey', array(
													"LOWER(cl_sortkey) LIKE '" . $dbr->strencode( strtolower( $sqlMatch )) . ":%'",
													"cl_to = 'V:" . $dbr->strencode( $version ) . "'" ), __METHOD__ );
		
							if( !$res->numRows( )) 
							{
								$topicTitle = $sqlMatch . ':' . $version;
								
								$tempArticle = new Article( Title::newFromText( $topicTitle ));
								if( !$tempArticle->exists( ))
								{
									/**
									 * Create the new article in the system;  if we have alternate text then set our H1 to this.
									 * Tag it with the currently selected version only.
									 */
									$content = '';
									if( strlen( $match[3] ))
										$content = '= ' . $match[3] . " =\n";
									else
										$content = '= ' . $topicTitle . " =\n";
										
									$content .= "\n[[Category:V:" . $version . "]]";
								
									$tempArticle->doEdit( $content, 'Auto-creation of topic via reference from another topic.', EDIT_NEW );									
								}								
							}				
						}
						
						/**
						 * Explicit link of the form:
						 * [[Documentation:Manual:Topic:Version|Some Alternate Text]]						 
						 */
						else if( sizeof( $pieces ) == 4 )
						{
							$version = PonyDocsVersion::GetSelectedVersion( );
							$topicTitle = $match[1];							
							
							$tempArticle = new Article( Title::newFromText( $topicTitle ));
							if( !$tempArticle->exists( ))
							{
								//echo "- Adding new article with title $topicTitle.<br>";
								/**
								 * Create the new article in the system;  if we have alternate text then set our H1 to this.
								 */
								$content = '';
								if( strlen( $match[3] ))
									$content = '= ' . $match[3] . " =\n";
								else
									$content = '= ' . $topicTitle . " =\n";
							
								$content .= "\n[[Category:V:" . $version . "]]";
								
								$tempArticle->doEdit( $content, 'Auto-creation of topic via reference from another topic.', EDIT_NEW );	
							} 
						}
					}
					
					/**
					 * Handle non-Documentation NS references, such as 'Dev:SomeTopic'.  This is much simpler -- if it doesn't exist,
					 * create it and add the H1.  Nothing else.
					 */
					else
					{						
						$tempArticle = new Article( Title::newFromText( $match[1] ));
						if( !$tempArticle->exists( ))
						{
							//echo "- Adding new article with title {$match[1]}.<br>";
							/**
							 * Create the new article in the system;  if we have alternate text then set our H1 to this.
							 */
							$content = '';
							if( strlen( $match[3] ))
								$content = '= ' . $match[3] . " =\n";
							else
								$content = '= ' . $match[1] . " =\n";
								
							$tempArticle->doEdit( $content, 'Auto-creation of topic via reference from another topic.', EDIT_NEW );							
						}
					}
					
				}
				/**
				 * Here we handle simple topic links:
				 * [[SomeTopic|Some Display Title]]
				 * Which assumes CURRENT manual in Documentation namespace.  It finds the topic which must share a version tag
				 * with the currently displayed title.
				 */
				else
				{					
					$pManual = PonyDocsManual::GetCurrentManual( );
					$version = PonyDocsVersion::GetSelectedVersion( );					
					if(!$pManual) {
						// Cancel out.
						return true;
					}
					/**
					 * Does this topic exist?  Look for a topic with this name tagged for the current version.
					 * If nothing is found, we create a new article.
					 */
					$sqlMatch = 'Documentation:' . $pManual->getShortName( ) . ':' . $match[1];
					$res = $dbr->select( 	'categorylinks', 'cl_sortkey', array(
											"LOWER(cl_sortkey) LIKE '" . $dbr->strencode( strtolower( $sqlMatch )) . ":%'",
											"cl_to = 'V:" . $dbr->strencode( $version ) . "'" ), __METHOD__ );

					if( !$res->numRows( ))
					{															
						$topicTitle = $sqlMatch . ':' . $version;						
						
						$tempArticle = new Article( Title::newFromText( $topicTitle ));
						if( !$tempArticle->exists( ))
						{
							/**
							 * Create the new article in the system;  if we have alternate text then set our H1 to this.
							 */
							$content = '';
							if( strlen( $match[3] ))
								$content = '= ' . $match[3] . " =\n";
							else
								$content = '= ' . $topicTitle . " =\n";
								
							$content .= "\n[[Category:V:" . $version . "]]";
						
							$tempArticle->doEdit( $content, 'Auto-creation of topic via reference from another topic.', EDIT_NEW );									
						}
					}
				}				
			}
		}
		return true;
	}
	
	/**
	 * This hook is called when 'edit' is selected for a title.  In this case we intercept it for TOC management pages which are
	 * NEW (do not yet exist and have content).  When this occurs we need to take the currently selected version and then populate
	 * the edit box with a version tag for it.  For some reason there is no way I can find to do this via the supplied EditPage
	 * object, nor does simply adding an inline script to set the content work.  So instead, the template sets a body_onload param
	 * telling it to call the 'ponydocsOnLoad' function.  We define it here to set the edit box.
	 *
	 * @param EditPage $editpage
	 * @return mixed 
	 */
	static public function onEdit_TOCPage( &$editpage )
	{
		global $wgTitle, $wgOut;
		
		if( !preg_match( '/^Documentation:(.*)TOC(.*)/i', $wgTitle->__toString( ), $match ))
			return true;

		if( !$wgTitle->exists( ))
		{
			$script = 	"function ponydocsOnLoad() {
							document.getElementById('wpTextbox1').value = '[[Category:V:" . PonyDocsVersion::GetSelectedVersion( ) . "]]';
						}";			
			$wgOut->addInLineScript( $script );			
		}
			
		return true;		
	}
	
	/**
	 * This handles calls to 'AlternateEdit' hook, which is called when action=edit.  You must return 'true' to proceed with the normal
	 * edit handling (which we do), or false to skip it if we wish to handle the edit call ourselves (which we don't.).  The concept here is
	 * we can implement cloning and inheritence handling here by injecting Ajax calls (see PonyDocsAjax.php) to the output.
	 *
	 * FOR CLONING:
	 * If this is a NEW edit (content is empty) then we need to take the topic name and see if we have one for any other version.
	 * If we do we need to output a set of links which calls an Ajax function (efPonyDocsAjaxTopicClone), passing it the topic name and version to clone
	 * from.  This Ajax call returns the content, which it should then place into the edit box overwriting anything within it.
	 *
	 * @static
	 * @param EditPage $editpage Our EditPage object used/created when editing.
	 * @return boolean
	 */
	static public function onEdit( &$editpage )
	{
		global $wgOut, $wgArticle, $wgTitle;		
		
		/**
		 * Only offer cloning to NEW articles?
		 */		 
		$article = new Article( Title::newFromText( $wgTitle->__toString( )));
		$article->getContent( );

		/**
		 * @FIXME: This is causing a big problem when NEW articles fail the onArticleSave check for bad or duplicate categories,
		 * because it is then routed through here?  If I edit a new article, save it, then go back and edit it and add a duplicate
		 * or bad version, the processing works.
		 */
		if( $article->mRevIdFetched != 0 )
			return true;
					
		$dbr = wfGetDB( DB_SLAVE );
		
		// Ignore anything not of the form Documentation:<manual>:<topic>:<version>.
		if( !preg_match( '/Documentation:(.*):(.*):(.*)/i', $wgTitle->__toString( ), $match ))
			return true;
			
		$baseTopic = sprintf( "Documentation:%s:%s", $match[1], $match[2] );
		
		/**
 		 * Select all of our topics which match this one (of any version) that is not our own.
		 */
		$qry =	"SELECT DISTINCT(cl_sortkey) " .
				"FROM categorylinks " .
				"WHERE LOWER(cl_sortkey) LIKE '" . strtolower( $baseTopic ) . ":%' " .
				"AND LOWER(cl_sortkey) NOT LIKE '" . $wgTitle->__toString( ) . "' " .
				"ORDER BY cl_sortkey ASC";
		
		$res = $dbr->query( $qry, __METHOD__ );
 		if( !$res->numRows( ))
 			return true;
 		
 		$out = '';
 			
 		/**
	 	 * Now select all the versions for each topic match we found.  We then append a link to our Ajax function for each version to our output
	  	 * passing the base topic and the version.  When done $output should be a series of version anchors calling the same Ajax function. 
	 	 */
 		while( $row = $dbr->fetchObject( $res ))
 		{ 			
			$vRes = $dbr->select( 'categorylinks', 'cl_to', "cl_sortkey = '" . $dbr->strencode( $row->cl_sortkey ) . "'", __METHOD__ );
			if( !$vRes->numRows( ))
				continue;
				
			while( $vRow = $dbr->fetchObject( $vRes ))
			{
				if( preg_match( '/^V:(.*)/i', $vRow->cl_to, $vmatch ))
					$out .= '<a "#" onClick="AjaxCloneTopic(\'' . $baseTopic . '\', \'' . $vmatch[1] . '\');">' . $vmatch[1] . '</a> ';	
			}
 		}
 		
 		$out = '<div><h3>Clone content from one of the following versions: ' . $out . '</h3></div>';
		
 		/**
		 * This is our actual JavaScript to handle the Ajax call.  For some reason if I try to pass the textarea element directly to the
		 * sajax_do_call, we lose all of our newlines in IE and in Firefox it only populates one time (additional clicks won't repopulate).
		 * So I had to create a callback that converts it to a String object then set it manually and it seems to work. 
	 	 */
 		$ajax =  <<<HEREDOC
 		 		 
 		function AjaxCloneTopic_callback( o ) {
			var s = new String( o.responseText );
			document.getElementById( 'wpTextbox1' ).value = s;
		}
		
		function AjaxCloneTopic( topic, version ) {						
			sajax_do_call( 'efPonyDocsAjaxTopicClone', [topic, version], AjaxCloneTopic_callback );
		}
		 		
HEREDOC;
 	
 		$wgOut->addInLineScript( $ajax );
 		$wgOut->addHTML( $out );
 		
		return true;
	}
	
	/**
	 * Here we handle any unknown/custom actions.  For now these are:
	 *  - 'print':  Produce printer ready output of a single topic or entire manual;  request param 'type' should
	 *		be set to either 'topic' or 'manual' and topic must be defined (as title).
	 *
	 * @static
	 * @param string $action
	 * @param Article $article
	 * @return boolean|string
	 */
	static public function onUnknownAction( $action, &$article )
	{
		global $wgRequest, $wgParser, $wgTitle;
		global $wgHooks;
		
		$ponydocs  = PonyDocsWiki::getInstance( );
		$dbr = wfGetDB( DB_SLAVE );
				
		/**
		 * This is accessed when we want to REMOVE version tags from a supplied topic.
		 *	title=	Topic to remove version tags from.
		 *  versions= List of versions, colon delimited, to remove.
		 *
		 * This is intended to be an AJAX call and produces no output.
		 */
		if( !strcmp( $action, 'ajax-removetags' ))
		{
			/**
 			 * First open the title and strip the [[Category]] tags from the content and save.
			 */
			$versions = split( ',', $wgRequest->getVal( 'versions' ));			
			$article = new Article( Title::newFromText( $wgRequest->getVal( 'title' )));
			$content = $article->getContent( );
			
			$findArray = $repArray = array( );
			foreach( $versions as $v )
			{
				$findArray[] = '/\[\[\s*Category\s*:\s*V:\s*' . $v . '\]\]/i';
				$repArray[] = '';
			}
			$content = preg_replace( $findArray, $repArray, $content );
			
			$article->doEdit( $content, 'Automatic removal of duplicate version tags.', EDIT_UPDATE );				

			/**
			 * Now update the categorylinks (is this needed?).
			 */
			$q =	"DELETE FROM categorylinks " .
					"WHERE LOWER(cl_sortkey) = '" . $dbr->strencode( strtolower( $wgRequest->getVal( 'title' ))) . "' " .
					"AND cl_to IN ('V:" . implode( "','V:", $versions ) . "')";
			
			$res = $dbr->query( $q, __METHOD__ );

			/**
			 * Do not output anything, this is an AJAX call.
			 */			
			die();
		}
		
		/**
		 * Our custom print action -- 'print' exists so we need to use our own.  Require that 'type' is set to 'topic' for the
		 * current topic or 'manual' for entire current manual.  The 'title' param should be set as well.  Output a print
		 * ready page.
		 */
		else if( !strcmp( $action, 'doprint' ))
		{
			$type = 'topic';
			if( $wgRequest->getVal( 'type' ) || strlen( $wgRequest->getVal( 'type' )))
			{
				if( !strcasecmp( $wgRequest->getVal( 'type' ), 'topic' ) && !strcasecmp( $wgRequest->getVal( 'type' ), 'manual' ))
				{
					// Invalid!
				}
				$type = strtolower( $wgRequest->getVal( 'type' ));
			}
			
			if( !strcmp( $type, 'topic' ))
			{
				$article = new Article( Title::newFromText( $wgRequest->getVal( 'title' )));
				$c = $article->getContent();
				
				die();				
			}

			die( "Print!" );
		}
		return true;
	}
	
	/**
	 * This hook is called before any form of substituation or parsing is done on the text.  $text is modifiable -- we can do
	 * any sort of substitution, addition/deleting, replacement, etc. on it and it will be reflected in our output.  This is
	 * perfect to doing wiki link substitution for URL rewriting and so forth.
	 *
	 * @static
	 * @param Parser $parser
	 * @param string $text
	 * @return boolean|string
	 */
	static public function onParserBeforeStrip( &$parser, &$text )
	{	
		global $action, $wgTitle, $wgArticlePath, $wgOut, $wgArticle, $wgPonyDocs, $action;

		$dbr = wfGetDB( DB_SLAVE );
		if(empty($wgTitle)) {
			return true;
		}
		
		// We want to do link substitution in all namespaces now.
		$doWikiLinkSubstitution = true;
		$matches = array( 	'/^Documentation:(.*):(.*):(.*)/',
							'/^Splexicon/' );					
		
		$doStripH1 = false;
		foreach( $matches as $m )
			if( preg_match( $m, $wgTitle->__toString( )))
				$doStripH1 = true;

		
		if( !strcmp( $action, 'submit' ) && preg_match( '/^Someone else has changed this page/i', $text ))
		{
			$text = '';
			return true;
		}
		
		
		/**
		 * Strip out ANY H1 HEADER.  This has the nice effect of only stripping it out during render and not during edit or
		 * anything.  We should only be doing this for Documentation namespace?
		 *
		 * Note, we've put false into the if statement, because we're 
		 * disabling this "feature", per WEB-2890.
		 *
		 * Keeping the code in, just in case we want to re-enable.
		 */		
		if( $doStripH1 && false )
			$text = preg_replace( '/^\s*=.*=.*\n?/', '', $text );	
			
		/**
		 * Handle our wiki links, which are always of the form [[<blah>]].  There are built-in functions however that also use
		 * this structure (like Category tags).  We need to filter these out AND filter out any external links.  The rest we
		 * need to grab and produce proper anchor's and replace in the output.  In each:
		 * 	0=Entire string to match
		 *  1=Title
		 *  2=Ignore
		 *  3=Display Text (optional)
		 * Possible forms:
		 *	[[TopicName]]								Translated to Documentation:<currentManual>:<topicName>:<selectedVersion>	
		 *	[[Documentation:<manual>:<topic>]]			Translated to Documentation:<manual>:<topic>:<selectedVersion>
		 *	[[Documentation:<manual>:<topic>:<version>]]No translation done -- exact link.
		 *	[[Namespace:Topic]]							No translation done -- exact link.
		 *  [[:Topic]]									Link to topic in global namespace - preceding colon required!
		 */		
			
		//if( $doWikiLinkSubstitution && preg_match_all( "/\[\[([A-Za-z0-9,:._ -]*)([|]?([A-Za-z0-9,:.'_!@\"()#$ -]*))\]\]/", $text, $matches, PREG_SET_ORDER ))
		if( $doWikiLinkSubstitution && preg_match_all( "/\[\[([A-Za-z0-9,:._ -]*)(\#[A-Za-z0-9 _-]+)?([|]?([A-Za-z0-9,:.'_?!@\/\"()#$ -]*))\]\]/", $text, $matches, PREG_SET_ORDER ))
		{								
			//echo '<pre>'; print_r( $matches ); die();
			/**
			 * For each, find the topic in categorylinks which is tagged with currently selected version then produce
			 * link and replace in output ($text).  Simple!
			 */
			$pManual = PonyDocsManual::GetCurrentManual( );
			// No longer bail on $pManual not being set.  We should only need it 
			// for [[Namespace:Topic]]

			foreach( $matches as $match )
			{
				/**
				 * Namespace used.  If NOT Documentation, just output the link.
				 */
				if( strpos( $match[1], ':' ) !== false )
				{	
					$pieces = split( ':', $match[1] );	

					/**
					 * [[Documentation:User:Topic]] => Documentation/<currentVersion>/User/Topic
					 */
					if( 3 == sizeof( $pieces ))
					{					
						$version = PonyDocsVersion::GetSelectedVersion( );
						$res = $dbr->select( 'categorylinks', 'cl_sortkey', 
							array( 	"LOWER(cl_sortkey) LIKE '" . $dbr->strencode( strtolower( $match[1] )) . ":%'",
						 			"cl_to = 'V:" . $version . "'" ), __METHOD__ );
						
						if( $res->numRows( ))
						{							
							$row = $dbr->fetchObject( $res );
														
							global $title;
							// Our title is our url.  We should check to see if 
							// latest is our version.  If so, we want to FORCE 
							// the URL to include /latest/ as the version 
							// instead of the version that the user is 
							// currently in.
							$tempParts = explode("/", $title);
							$latest = false;
							if(!empty($tempParts[1]) && (!strcmp($tempParts[1], "latest"))) {
								$latest = true;
							}
							// Okay, let's determine if the VERSION that the user is in is latest, 
							// if so, we should set latest to true.
							if(PonyDocsVersion::GetSelectedVersion() == PonyDocsVersion::GetLatestReleasedVersion()) {
								$latest = true;
							}
							$href = str_replace( '$1', 'Documentation/' . ($latest ? "latest" : PonyDocsVersion::GetSelectedVersion( )) . '/' . $pieces[1] . '/' . preg_replace( '/([^' . str_replace( ' ', '', Title::legalChars( )) . '])/', '', $pieces[2] ), $wgArticlePath );//
							$href .= $match[2];
							if(isset($_SERVER['SERVER_NAME'])) {
								$text = str_replace( $match[0], '[http://' . $_SERVER['SERVER_NAME'] . $href . ' ' . ( strlen( $match[4] ) ? $match[4] : $match[1] ) . ']', $text );																		 					
							}
						}
																
					}
					
					/**
					 * [[Documentation:User:Topic:Veresion]] => Documentation/Version/User/Topic
					 */
					else if( 4 == sizeof( $pieces ))
					{				
						$href = str_replace( '$1', 'Documentation/' . $pieces[3] . '/' . $pieces[1] . '/' . preg_replace( '/([^' . str_replace( ' ', '', Title::legalChars( )) . '])/', '', $pieces[2] ), $wgArticlePath );//
						$href .= $match[2];

						$text = str_replace( $match[0], '[http://' . $_SERVER['SERVER_NAME'] . $href . ' ' . ( strlen( $match[4] ) ? $match[4] : $match[1] ) . ']', $text );													 					
					}
				}
				else
				{					
					// Check if our title is in Documentation, if not, don't 
					// modify the match. 
					if(!preg_match( '/^Documentation:.*:.*:.*/i', $wgTitle->__toString( )))
						continue;
					$version = PonyDocsVersion::GetSelectedVersion( );
					$page = 'documentation:' . strtolower( $pManual->getShortName( )) . ':' . strtolower( $match[1] );
					
					$res = $dbr->select( 'categorylinks', 'cl_sortkey', 
						array( 	"LOWER(cl_sortkey) LIKE '" .  $dbr->strencode( $page )  . ":%'",
							 	"cl_to = 'V:" . $version . "'" ), __METHOD__ );				
						
					/**
					 * We might need to make it a "non-link" at this point instead of skipping it.
					 */
					if( !$res->numRows( ))
						continue;						
						
					/**
					 * Replace it with a proper [[]] link to the actual article.
					 */
					$row = $dbr->fetchObject( $res );	
											
					$href = str_replace( '$1', 'Documentation/' . $version . '/' . $pManual->getShortName( ) . '/' . preg_replace( '/([^' . str_replace( ' ', '', Title::legalChars( )) . '])/', '', $match[1] ), $wgArticlePath );//							
					$href .= $match[2];

					$text = str_replace( $match[0], '[http://' . $_SERVER['SERVER_NAME'] . $href . ' ' . ( strlen( $match[4] ) ? $match[4] : $match[1] ) . ']', $text );													
 					
				}				
			}
		}
		return true;
	}
	

	/**
	 * Handles special cases for permissions, which include:
	 * 
	 * 	- Only AUTHOR group can edit/submit the manuals and versions pages.
	 * 	- Only AUTHORS and EMPLOYEES can edit/submit pages in FAQ, Splexicon, and Documentation namespace.
	 *
	 * @param Title $title The title to test permission against.
	 * @param User $user The user requestion the action.
	 * @param string $action The actual action (edit, view, etc.)
	 * @param boolean $result The result, which we store in;  true=allow, false=do not.
	 * @return boolean Return true to continue checking, false to stop checking, null to not care.
	 */
	static public function onUserCan( $title, $user, $action, &$result )
	{		
		global $wgExtraNamespaces;

		if( !strcmp( 'edit', $action ) || !strcmp( 'submit', $action ))
		{
			/**
			 * Only doc team can edit manuals/versions pages.
			 */
			if( !strcmp( $title->__toString( ), PONYDOCS_DOCUMENTATION_VERSION_TITLE ) ||
				!strcmp( $title->__toString( ), PONYDOCS_DOCUMENTATION_MANUALS_TITLE))
			{
				$groups = $user->getGroups();
				if( !in_array( PONYDOCS_AUTHOR_GROUP, $groups ))
				{
					$result = false;
					return false;
				}					
			}
			
			/**
			 * Disallow edit/submit for documentation, FAQ, and Splexicon namespaces (and pages) unless
			 * the user is in the employee or authors/docteam group.
			 */
			if(	( $title->getNamespace( ) == 100 ) ||
				( $title->getNamespace( ) == 140 ) ||
				( $title->getNamespace( ) == 150 ) ||
				( !strcmp( $title->__toString( ), $wgExtraNamespaces[100] ))) 
			{
				$groups = $user->getGroups();
				if( !in_array( PONYDOCS_AUTHOR_GROUP, $groups ) && !in_array( PONYDOCS_EMPLOYEE_GROUP, $groups ))
				{
					$result = false;
					return false;
				}
			}	
		}

		return true;		
	}

	/**
	 * Returns the pretty url of a document if it's in the Documentation 
	 * namespace and is a topic in a manual.
	 */
	static public function onGetFullURL($title, $url, $query) {
		global $wgScriptPath;
		// Check to see if we're in the Documentation namespace when viewing
		if( preg_match( '/^' . str_replace("/", "\/", $wgScriptPath) . '\/Documentation\/(.*)$/i', $_SERVER['PATH_INFO'])) {
			if( !preg_match( '/Documentation:/', $title->__toString( )))
				return true;
			// Okay, we ARE in the documentation namespace.  Let's try and rewrite 
			$url = preg_replace("/Documentation:([^:]+):([^:]+):([^:]+)$/i", "Documentation/" . PonyDocsVersion::GetSelectedVersion() . "/$1/$2", $url);
			return true;
		}
		else if(preg_match('/Documentation:/', $title->__toString())) {
			$editing = false; 		// This stores if we're editing an article or not
			if(preg_match('/&action=submit/', $_SERVER['PATH_INFO'])) {
				// Then it looks like we are editing.
				$editing = true;
			}
			// Okay, we're not in the documentation namespace, but we ARE 
			// looking at a documentation namespace title.  So, let's rewrite
			if(!$editing) {
				$url = preg_replace("/Documentation:([^:]+):([^:]+):([^:]+)$/i", "Documentation/$3/$1/$2", $url);
			}
			else {
				// Then we should inject the user's current version into the 
				// article.
				$currentVersion = PonyDocsVersion::GetSelectedVersion();
				$targetVersion = $currentVersion;
				// Now, let's get the PonyDocsAliasArticle, and fetch the versions 
				// it applies to.
				$title = Title::newFromText($title->__toString());
				$article = new PonyDocsAliasArticle($title);
				$topic = new PonyDocsTopic($article);
				$topicVersions = $topic->getVersions();
				$found = false;
				// Now let's go through each version and make sure the user's 
				// current version is still in the topic.
				foreach($topicVersions as $version) {
					if($version->getName() == $currentVersion) {
						$found = true;
						break;
					}
				}
				if(!$found) {
					// This is an edge case, it shouldn't happen often.  But 
					// this means that the editor removed the version the user 
					// is in from the topic.  So in this case, we want to 
					// return the Documentation url with the version being the 
					// latest released version in the topic.
					$targetVersion = "latest";
					foreach($topicVersions as $version) {
						if($version->getStatus() == "released") {
							$targetVersion = $version->getName();
						}
					}
				}
				$url = preg_replace("/Documentation:([^:]+):([^:]+):([^:]+)$/i", "Documentation/$targetVersion/$1/$2", $url);
			}
			return true;
		}
		return true;
	}

	/**
	 * Clears the navigation cache for all versions.
	 * Warning: This removes ALL regular files inside the navcachedir specified 
	 * in the server.config.php.
	 */
	static public function ClearNavCache() {
		global $ponydocsMediaWiki;
		// Need to delete all regular files (if possible) in the cache directory
		if(!$dbh = @opendir($ponydocsMediaWiki['navcachedir'])) {
			return;
		}
		while(false !== ($fileName = readdir($dbh))) {
			if($fileName == "." || $fileName == "..") {
				continue;
			}
			if(!is_dir($ponydocsMediaWiki['navcachedir'] . "/" . $fileName)) {
				// Regular file, attempt to unlink
				unlink($ponydocsMediaWiki['navcachedir'] . "/" . $fileName);
			}
		}
		return true;
	}

	/**
	 * Returns the manual data for a version in cache.  If the cache is not populated for 
	 * that version, then build it and return it.
	 *
	 */
	static public function fetchNavDataForVersion($version) {
		global $ponydocsMediaWiki;
		$navCacheFile = $ponydocsMediaWiki['navcachedir'] . "/" . md5($version . $ponydocsMediaWiki['navcachesalt']) . "-2"; // Added -2 to avoid using old cache files.
		$rightNow = time();
		$filemtime = @filemtime($navCacheFile); // Suppress warning, if the file does not exist.  Returns false if doesn't exist.
		if(!$filemtime || ($filemtime + $ponydocsMediaWiki['navcachettl']) < $rightNow) {
			error_log("INFO [PonyDocsExtension::fetchNavDataForVersion] Creating new navigation cache file for version $version");
			// The cache file does not exist, or is stale.  Let's create a new 
			// one.
			// Store the old version, so we can set it after we're done loading 
			// the requested version.
			$oldVersion = PonyDocsVersion::GetSelectedVersion();
			PonyDocsVersion::SetSelectedVersion($version);
			$ver = PonyDocsVersion::GetVersionByName(PonyDocsVersion::GetSelectedVersion());
			$manuals = PonyDocsManual::LoadManuals(true);
			$cacheEntry = array();
			foreach($manuals as $manual) {
				$toc = new PonyDocsTOC($manual, $ver);
				list($toc, $prev, $next, $start) = $toc->loadContent();
				foreach($toc as $entry) {
					if(isset($entry['link']) && $entry['link'] != '') {
						// Found first article.
						$cacheEntry[] = array('shortName' => $manual->getShortName(),
											  'longName' => $manual->getLongName(),
											  'firstUrl' => $entry['link']);
						break;
					}
				}
			}
			file_put_contents($navCacheFile, serialize($cacheEntry));
			// Restore old version
			PonyDocsVersion::SetSelectedVersion($oldVersion);
			PonyDocsManual::LoadManuals(true);
		}
		else {
			error_log("INFO [PonyDocsExtension::fetchNavDataForVersion] Fetching navigation cache from $navCacheFile");
			$cacheEntry = unserialize(file_get_contents($navCacheFile));
		}
		return $cacheEntry;
	}

	/**
	 * Hook function which is used to determine if a url is used to determine 
	 * the first article in a manual for the user's version.  If so, try and 
	 * find the first article and redirect.
	 */
	public function onArticleFromTitleQuickLookup($title, $article) {
		global $wgScriptPath;
		if(preg_match('/&action=edit/', $_SERVER['PATH_INFO'])) {
			// Check referrer and see if we're coming from a doc page.
			// If so, we're editing it, so we should force the version 
			// to be from the referrer.
			if(preg_match('/^' . str_replace("/", "\/", $wgScriptPath) . '\/Documentation\/((latest|[\w\.]*)\/)?(\w+)\/?/i', $_SERVER['HTTP_REFERER'], $match)) {
				$targetVersion = $match[2];
				if($targetVersion == "latest") {
					PonyDocsVersion::SetSelectedVersion(PonyDocsVersion::GetLatestReleasedVersion()->getName());
				}
				else {
					PonyDocsVersion::SetSelectedVersion($targetVersion);
				}
			}
		}

		// The following regex is better understood with a bottle of whiskey.
		// Or you can look at WEB-3862 if you want to be a party pooper.
		if(preg_match('/^' . str_replace("/", "\/", $wgScriptPath) . '\/Documentation\/((latest|[\w\.]*)\/)?(\w+)\/?$/i', $_SERVER['PATH_INFO'], $match)) {
			$targetManual = $match[3];
			$targetVersion = $match[2];

			// User wants to find first topic in a requested manual.
			// Load up versions
			PonyDocsVersion::LoadVersions();

			// Determine version
			if($targetVersion == '') {
				// No version specified, use the user's selected version
				$ver = PonyDocsVersion::GetVersionByName(PonyDocsVersion::GetSelectedVersion());
			}
			else if(strtolower($targetVersion) == "latest") {
				// User wants the latest version.
				$ver = PonyDocsVersion::GetLatestReleasedVersion();
			}
			else {
				// Okay, they want to get a version by a specific name
				$ver = PonyDocsVersion::GetVersionByName($targetVersion);
			}
			if(!$ver) {
				header("Location: " . $wgScriptPath . "/Documentation");
				die();
			}
			// Okay, the version is valid, let's set the user's version.
			PonyDocsVersion::SetSelectedVersion($ver->getName());
			PonyDocsManual::LoadManuals();
			$man = PonyDocsManual::GetManualByShortName($targetManual);
			if(!$man) {
				// Rewrite to Main documentation
				header("Location: " . $wgScriptPath . "/Documentation");
				die();
			}
			// Get the TOC out of here! heehee
			$toc = new PonyDocsTOC($man, $ver);
			list($toc, $prev, $next, $start) = $toc->loadContent();
			foreach($toc as $entry) {
				if(isset($entry['link']) && $entry['link'] != "") {
					// We found the first article in the manual with a link.  
					// Redirect to it.
					header("Location: " . $entry['link']);
					die();
				}
			}
			die();
		}
		return false;

	}

	static public function handle404(&$out) {
		global $wgOut;
		$wgOut->clearHTML();
		$wgOut->setPageTitle("The Requested Topic Does Not Exist");
		$wgOut->addHTML("<p>Hi!  This page does not exist, or has been removed from the Documentation.</p>");
		$wgOut->addHTML("<p>To find what you need, you can:<ul><li>Search using the box in the upper right</li></ul><p>OR</p><ul><li>Select a manual from the list above and then a topic from the Table of Contents on the left</li></ul><p>Thanks!</p>");
		$wgOut->setArticleFlag(false);
		$wgOut->setStatusCode(404);
		return true;
	}


	/**
	 * Called when an article is deleted, we want to purge any doclinks entries 
	 * that refer to that article if it's in the documentation namespace.
	 */
	static public function onArticleDelete(&$article, &$user, &$user, &$error) {
		$title = $article->getTitle();
		if( !preg_match( '/^Documentation:/i', $title->__toString( ), $matches )) {
			return true;
		}
		// Okay, article is in doc namespace, pass it over to our utility 
		// function.
		PonyDocsExtension::deleteDocLinks($article);
		PonyDocsExtension::clearArticleCategoryCache($article);
		return true;
	}

	/**
	 * When an article is fully saved, we want to update the doclinks for that 
	 * article in our doclinks table.  Only if it's in the documentation 
	 * namepsace, however.
	 *
	 */
	static public function onArticleSaveComplete(&$article, &$user, $text, $summary, &$minoredit, $watchthis,
												 $sectionanchor, &$flags, $revision, &$status, $baseRevId) {

		$title = $article->getTitle();
		if( !preg_match( '/^Documentation:/i', $title->__toString( ), $matches )) {
			return true;
		}
		// Okay, article is in doc namespace, pass it over to our utility 
		// function.
		PonyDocsExtension::updateDocLinks($article, $text);

		// Now we need to remove any pdf books for this topic.
		// Since the person is editing the article, it's safe to say that the 
		// version and manual can be fetched from the classes and not do any 
		// manipulation on the article itself.
		$version = PonyDocsVersion::GetSelectedVersion();
		$manual = PonyDocsManual::GetCurrentManual($title);	

		if($manual != null) {
			// Then we are in the documentation namespace, but we're not part of 
			// manual.
			// Clear any PDF for this manual
			PonyDocsPdfBook::removeCachedFile($manual->getShortName(), $version);
		}

		// Clear any TOC cache entries this article may be related to.
		$topic = new PonyDocsTopic($article);
		$manVersionList = $topic->getVersions( );
		// Clear all TOC cache entries for each version.
		if($manual) {
			foreach($manVersionList as $version) {
				PonyDocsTOC::clearTOCCache($manual, $version);
			}
		}
		PonyDocsExtension::clearArticleCategoryCache($article);

		return true;
	}

	/**
	 * Deletes PonyDocs category cache associated with the article
	 * @param Article $article
	 */
	static public function clearArticleCategoryCache($article) {
		$topic = new PonyDocsTopic($article);
		$cache = PonyDocsCache::getInstance();
		$ponydocsVersions = $topic->getVersions();
		if (count($ponydocsVersions) > 0) {
			foreach ($ponydocsVersions as $ver) {
				$cache->remove("category-Category:V:" . $ver->getName());
			}
		}
	}

	/**
	 * Deletes Doc Links entries from table which refer to an article from both 
	 * from and to entries.
	 */
	static public function deleteDocLinks($article) {
		$dbh = wfGetDB(DB_MASTER);
		$topic = new PonyDocsTopic($article);
		$ponydocsVersions = $topic->getversions();
		$versions = array();
		foreach($ponydocsVersions as $ver) {
			$versions[] = $ver->getName();
		}
		foreach($versions as $currentVersion) {
			$title = $article->getTitle()->getFullText();
			$humanReadableTitle = preg_replace("/Documentation:([^:]+):([^:]+):([^:]+)$/i", "Documentation/" . $currentVersion . "/$1/$2", $title);
			// $humanReadableTitle now contains human readable title.  We're going 
			// to store this in the "from" column of our doclinks table.
			// But first we need to delete any instances of this from the 
			// doclinks table.
			$dbh->delete('ponydocs_doclinks', array('from_link' => $humanReadableTitle));
			$dbh->delete('ponydocs_doclinks', array('to_link' => $humanReadableTitle));
		}
		return true;
	}

	
	/**
	 * Updates Doc Links table for the article being passed in.
	 * @param Article $article the article to be updated with
	 */
	static public function updateDocLinks($article, $content) {
		$dbh = wfGetDB(DB_MASTER);
		$topic = new PonyDocsTopic($article);
		$ponydocsVersions = $topic->getversions();
		$versions = array();
		foreach($ponydocsVersions as $ver) {
			$versions[] = $ver->getName();
		}
		foreach($versions as $currentVersion) {
			$title = $article->getTitle()->getFullText();
			$humanReadableTitle = preg_replace("/Documentation:([^:]+):([^:]+):([^:]+)$/i", "Documentation/" . $currentVersion . "/$1/$2", $title);
			// $humanReadableTitle now contains human readable title.  We're going 
			// to store this in the "from" column of our doclinks table.
			// But first we need to delete any instances of this from the 
			// doclinks table.
			$dbh->delete('ponydocs_doclinks', array('from_link' => $humanReadableTitle));
			$regex = "/\[\[([A-Za-z0-9,:._ -]*)(\#[A-Za-z0-9 _-]+)?([|]?([A-Za-z0-9,:.'_?!@\/\"()#$ -]*))\]\]/";
			if(preg_match_all($regex, $content, $matches, PREG_SET_ORDER)) {
				foreach($matches as $match) {
					if( strpos( $match[1], ':' ) !== false ) {
						$pieces = split( ':', $match[1] );
					}
					// We're only interested in Documentation  namespace links.  
					// Forget everything else.
					if($pieces[0] != "Documentation") {
						continue;
					}
					$toUrl = false;
					// Okay, let's evaluate based on the different "forms" our 
					// internal documentation links can take.
					if(sizeof($pieces) == 3) {
						// Handles example of:
						// [[Documentation:User:Topic]] -> 
						// Documentation/version/User/Topic
						$toUrl = "Documentation/" . $currentVersion . "/" . $pieces[1] . "/" . $pieces[2];
					}
					else if(sizeof($pieces) == 4) {
						// Handles examples of:
						// [[Documentation:User:Topic:Version]] => 
						// Documentation/Version/User/Topic
						if($pieces[1] == "latest")
							$pieces[1] = $latestVersion;
						$toUrl = "Documentation/" . $pieces[1] . "/" . $pieces[2] . "/" . $pieces[3];
					}
					// Okay...
					if($toUrl) {
						// We got a toUrl, let's put a record in the DB.
						$dbh->insert("ponydocs_doclinks", array('from_link' => $humanReadableTitle,
															  'to_link' => $toUrl));
					}
				}
			}
		}
		return true;
	}

	static public function isSpeedProcessingEnabled() {
		return PonyDocsExtension::$speedProcessingEnabled;
	}

	static public function setSpeedProcessing($enabled) {
		PonyDocsExtension::$speedProcessingEnabled = $enabled;
	}

	/**
	 * Used to render a hidden text field which will hold what version we were 
	 * in.  This forced the following edit submission to put us back in the 
	 * version we were browsing.
	 */
	static public function onShowEditFormFields(&$editpage, &$output) {
		// Add our form element to the top of the form.
		$version = PonyDocsVersion::GetSelectedVersion();
		$output->mBodytext .= "<input type=\"hidden\" name=\"ponydocsversion\" value=\"" . $version . "\" />";
		return true;
	}

};
/**
 * End of file.
 */