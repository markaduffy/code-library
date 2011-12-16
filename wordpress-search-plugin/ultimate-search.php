<?php 

/*
Plugin Name: ComponentOne Wordpress Ultimate Search
Description: Something that will search all of wordpress!
Version: 0.1
Author: Mark Duffy
*/

class UltimateSearch {


	/**
	 * display_search
	 *
	 * Main function. Gets the output depending on if page posted or if AJAX is used.
	 *
	 * @param 	string 	$section
	 * @param 	array 	$platforms
	 * @param 	string 	$search_terms	 
	 * @param 	string 	$advanced_search_type	 
	 * @param 	int 	$offset
	 * @param 	int 	$num
	 * @return 	array	$results
	 *
	 */
	function display_search($section, $platforms, $search_terms, $advanced_search_type, $offset, $num){

		if ($search_terms == ''){
			return array();
		}

		switch($section){
			case 'blog':
				$results = $this->get_blog_results($platforms, $search_terms, $advanced_search_type, $offset, $num);
				break;
			case 'blog_sample':
				$results = $this->get_blog_sample_results($platforms, $search_terms, $advanced_search_type, $offset, $num);
				break;
			case 'forum':
				$results = $this->get_forum_results($platforms, $search_terms, $advanced_search_type, $offset, $num);
				break;
		}

		return $results;

	}

	/**
	 * get_record_count
	 *
	 * Returns record count for pagination
	 *
	 * @param 	string 	$section
	 * @param 	array 	$platforms
	 * @param 	string 	$search_terms
	 * @return 	int		$count
	 *
	 */
	function get_record_count($section, $platforms, $search_terms){

		global $wpdb;

		if ($search_terms == ''){
			return 0;
		}

		//@MD Magic Quotes are switched on. Remove slashes before doing regex. We will escape array values after.
		$search_terms = stripslashes(stripslashes($search_terms));
		$search_terms = preg_split('#\s*("[^"]*")\s*|\s+#', $search_terms, -1 , PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

		if(count($search_terms) > 1){

			$i = 0;

			foreach($search_terms As $token){
		
				$i++;

				$token = mysql_real_escape_string($token);

				$new_search_terms .= " +" . $token;

				//@MD Tag Search
				$tag_search .= "EXISTS (SELECT
											 terms.name
											FROM wp_term_relationships term_rel
											LEFT JOIN wp_term_taxonomy term_tax
											 ON term_tax.term_taxonomy_id = term_rel.term_taxonomy_id
											LEFT JOIN wp_terms terms
											 ON term_tax.term_id = terms.term_id
											WHERE object_id = post.ID &&
											 term_tax.taxonomy = 'post_tag' && terms.name LIKE '%$token%')";

				if ($i < count($search_terms)){
					$tag_search .= " || ";
				}

			}

		} else {

			$st = mysql_real_escape_string($search_terms[0]);

			//@MD Tag Search
			$tag_search = "EXISTS (SELECT
										 terms.name
										FROM wp_term_relationships term_rel
										LEFT JOIN wp_term_taxonomy term_tax
										 ON term_tax.term_taxonomy_id = term_rel.term_taxonomy_id
										LEFT JOIN wp_terms terms
										 ON term_tax.term_id = terms.term_id
										WHERE object_id = post.ID &&
										 term_tax.taxonomy = 'post_tag' && terms.name LIKE '%$st%')";

			$new_search_terms = $st;
		}

		switch($section){
			case "blog":

				$platform_conditions = $this->platform_condition_builder('blogs', $platforms);
				
				$conditions = " WHERE (MATCH(post_search.post_content,post_search.post_title) AGAINST ('$new_search_terms' IN BOOLEAN MODE) || ($tag_search) ) && post.post_status = 'publish' && post.post_type = 'post' && (term_tax.description != '' && term_tax.description NOT LIKE '%sample%') $platform_conditions";

				$sql = "SELECT
						 COUNT(*)
						FROM wp_posts post
						LEFT JOIN wp_term_relationships term_rel
						 ON post.ID = term_rel.object_id
						LEFT JOIN wp_term_taxonomy term_tax
						 ON term_rel.term_taxonomy_id = term_tax.term_taxonomy_id
						LEFT JOIN wp_terms terms
 						 ON term_tax.term_id = terms.term_id
						LEFT JOIN wp_posts_fulltext_search post_search 
						 ON post.ID=post_search.post_id
						$conditions
						GROUP BY post.ID";

				$results = $wpdb->get_results($sql);
				$count = count($results);

				break;
			case "blog_sample":

				//$platform_conditions = $this->platform_condition_builder('samples', $platforms);

				$sql = "SELECT * FROM sl_samples s JOIN sl_samples_tags st ON s.id = st.sample_id JOIN sl_tags t ON st.tag_id = t.id WHERE (MATCH(s.title,s.description) AGAINST('$new_search_terms' IN BOOLEAN MODE) || (EXISTS (SELECT t.title FROM sl_samples_tags st JOIN sl_tags t ON t.id = st.tag_id WHERE st.sample_id = s.id && t.title LIKE '%$new_search_terms%'))) GROUP BY s.title";


				$results = $wpdb->get_results($sql);
				$count = count($results);

				break;
		}

		return $count;

	}

	/**
	 * get_forum_record_count
	 *
	 * Returns record count for pagination
	 *
	 * @param 	array 	$platforms
	 * @param 	string 	$search_terms
	 * @return 	int		$count
	 *
	 */
	function get_forum_record_count($platforms, $search_terms){

		global $wpdb;

		if ($search_terms == ''){
			return 0;
		}

		//@MD Magic Quotes are switched on. Remove slashes before doing regex. We will escape array values after.
		$search_terms = stripslashes(stripslashes($search_terms));
		$search_terms = preg_split('#\s*("[^"]*")\s*|\s+#', $search_terms, -1 , PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);




		if(count($search_terms) > 1){
			foreach($search_terms As $token){
				$new_search_terms .= " +" . mysql_real_escape_string($token);
			}
		} else {
			$new_search_terms = $search_terms[0];
		}
	
		$platform_conditions = $this->platform_condition_builder('forums', $platforms);
		
		// @MD condition builder. Used for advanced search
		$conditions = "WHERE MATCH(forum_search.post_text,forum_search.topic_title) AGAINST('$new_search_terms' IN BOOLEAN MODE) $platform_conditions";

		$sql = "SELECT SQL_NO_CACHE COUNT(*) FROM
				(SELECT
				  forum_search.topic_id
				FROM bb_topics_posts_fulltext_search forum_search
				JOIN bb_topics topics
				  ON forum_search.topic_id = topics.topic_id
				JOIN bb_forums forums
				  ON topics.forum_id = forums.forum_id
				$conditions
				GROUP BY forum_search.topic_id)a";

		$results = $wpdb->get_var($sql);
		$count = $results;

		return $count;

	}

	/**
	 * platform_condition_builder
	 *
	 * Builds the conditions for the search SQL depending on posted filters
	 *
	 * @param 	string 	$section
	 * @param 	array 	$platforms
	 * @return 	string	$platform_conditions
	 *
	 */
	private function platform_condition_builder($section, $platforms){
		
		if (isset($_SESSION['platforms'])){
			$selected_platforms = $_SESSION['platforms'];
		} else {
			$selected_platforms = $platforms;
		}

		

		$platform_conditions = "";

		if ($selected_platforms){

			if ($section == 'samples') {
				$cat_id = 'terms.term_id';
				$meta_key = 'sample_id';
			}

			if ($section == 'blogs') {
				$cat_id = 'terms.term_id';
				$meta_key = 'blog_id';
			}

			if ($section == 'forums') {
				$cat_id = 'forum_search.parent_group_id';
				$meta_key = 'forum_id';
			}
			
			$platform_conditions .= " && $cat_id IN (SELECT search_meta.meta_value FROM search_categorys search_cat JOIN search_meta ON search_cat.id = search_meta.category_id WHERE meta_key = '$meta_key' && (";

			$i = 0;
			$platform_count = count($selected_platforms);
			foreach($selected_platforms as $platform){
				
				$i++;

				if ($i < $platform_count){
					$sep = " || ";
				} else if ($i == $platform_count){
					 $sep = "))";
				}

				$platform_conditions .= "search_cat.id = $platform" . $sep;

			}

			return $platform_conditions;

		} else {
			return false;
		}

	}

	/**
	 * super_unique
	 *
	 * Returns only unique records from array. Stops need for using slow SQL grouping
	 *
	 * @param 	array 	$arr
	 * @return 	array	$result
	 *
	 */
	private function super_unique($arr){

	  $result = array_map("unserialize", array_unique(array_map("serialize", $arr)));

	  foreach ($result as $key => $value){
	    if ( is_array($value) )
	    {
	      $result[$key] = $this->super_unique($value);
	    }
	  }

	  return $result;
	}


	/**
	 * get_forum_results
	 *
	 * Return array of forum search results
	 *
	 * @param 	array 	$platforms
	 * @param 	string 	$search_terms
	 * @param 	string 	$advanced_search_type
	 * @param 	int 	$offset
	 * @param 	int 	$num
	 * @return 	array	$forum_results
	 *
	 */
	private function get_forum_results($platforms, $search_terms, $advanced_search_type, $offset, $num){
		
		global $wpdb;

		// check $offset and $num are integers
		if (!is_int($offset) || !is_int($num)){
			die("Error. Paging must have integers!");
		}

		$platform_conditions = $this->platform_condition_builder('forums', $platforms);

		if ($advanced_search_type == 'date'){
			$search_type = "forum_search.topic_last_post_time";
		} else {
			$search_type = "score";
		}

		//@MD Magic Quotes are switched on. Remove slashes before doing regex. We will escape array values after. Search terms are split and place in an array.
		$search_terms = stripslashes(stripslashes($search_terms));
		$search_terms = preg_split('#\s*("[^"]*")\s*|\s+#', $search_terms, -1 , PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

		if(count($search_terms) > 1){
			foreach($search_terms As $token){
				$new_search_terms .= " +" . mysql_real_escape_string($token);
			}
		} else {
			$new_search_terms = $search_terms[0];
		}

		$conditions = "WHERE MATCH(forum_search.post_text,forum_search.topic_title) AGAINST('$new_search_terms' IN BOOLEAN MODE) $platform_conditions";

		$limit = " LIMIT $offset,$num";
		$sql = "SELECT
				  forum_search.topic_id,
				  forum_search.topic_title,
				  topics.topic_slug,
				  forum_search.post_id,
				  forum_search.topic_posts,
				  forum_search.topic_poster_name,
				  forum_search.topic_last_post_id,
				  forum_search.forum_id,
				  forum_search.parent_group_id,
				  forum_search.child_group_id,
				  forum_search.topic_last_post_time,
				  forums.forum_name AS group_name,
				  parent.slug AS parent_slug,
				  child.slug AS child_slug,
				  MATCH(forum_search.post_text,forum_search.topic_title) AGAINST('$new_search_terms') AS score
				FROM bb_topics_posts_fulltext_search forum_search
				JOIN bb_topics topics
				  ON forum_search.topic_id = topics.topic_id
				JOIN bb_forums forums
				  ON topics.forum_id = forums.forum_id
				JOIN wp_bp_groups parent
				  ON forum_search.parent_group_id = parent.id
				JOIN wp_bp_groups child
				  ON forum_search.child_group_id = child.id
				$conditions
				GROUP BY forum_search.topic_id
				ORDER BY $search_type DESC
				$limit";

		$results = $wpdb->get_results($sql, ARRAY_A);

		foreach($results As $row){
			
			$topic_id = $row['topic_id'];

			//@MD - Check if topic has a post marked answered
			$answered_sql = "SELECT * FROM bb_meta WHERE object_id = $topic_id && meta_key = '_topic_answer_post'";

			if ($wpdb->get_row($answered_sql)){
				$answered = 1;
			} else {
				$answered = 0;
			}

			$forum_results[] = array(
									"topic_id" => $row['topic_id'],
									"topic_title" => $row['topic_title'],
									"topic_slug" => $row['topic_slug'],
									"post_id" => $row['post_id'],
									"topic_posts" => $row['topic_posts'],
						            "topic_poster_name" => $row['topic_poster_name'],
						            "topic_last_post_id" => $row['topic_last_post_id'],
						            "forum_id" => $row['forum_id'],
						            "parent_group_id" => $row['parent_group_id'],
						            "child_group_id" => $row['child_group_id'],
						            "topic_last_post_time" => $row['topic_last_post_time'],
						            "group_name" => $row['group_name'],
						           	"parent_slug" => $row['parent_slug'],
						            "child_slug" => $row['child_slug'],
						            "score" => $row['score'],
						            "answered" => $answered
								);

		}

		return $forum_results;

	}

	/**
	 * get_blog_results
	 *
	 * Returns array of blog search results
	 *
	 * @param 	array 	$platforms
	 * @param 	string 	$search_terms
	 * @param 	string 	$advanced_search_type
	 * @param 	int 	$offset
	 * @param 	int 	$num
	 * @return 	array	$blog_posts
	 *
	 */
	private function get_blog_results($platforms, $search_terms, $advanced_search_type, $offset, $num){
		
		global $wpdb;

		// check $offset and $num are integers
		if (!is_int($offset) || !is_int($num)){
			die("Error. Paging must have integers!");
		}

		$platform_conditions = $this->platform_condition_builder('blogs', $platforms);

		if ($advanced_search_type == 'date'){
			$search_type = "post.post_date";
		} else {
			$search_type = "score";
		}

		//@MD Magic Quotes are switched on. Remove slashes before doing regex. We will escape array values after. Search terms are split and place in an array.
		$search_terms = stripslashes(stripslashes($search_terms));
		$search_terms = preg_split('#\s*("[^"]*")\s*|\s+#', $search_terms, -1 , PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

		if(count($search_terms) > 1){

			$i = 0;

			foreach($search_terms As $token){
		
				$i++;

				$token = mysql_real_escape_string($token);
				
				$new_search_terms .= " +" . $token;
			
				//@MD Tag Search
				$tag_search .= "EXISTS (SELECT
											 terms.name
											FROM wp_term_relationships term_rel
											LEFT JOIN wp_term_taxonomy term_tax
											 ON term_tax.term_taxonomy_id = term_rel.term_taxonomy_id
											LEFT JOIN wp_terms terms
											 ON term_tax.term_id = terms.term_id
											WHERE object_id = post.ID &&
											 term_tax.taxonomy = 'post_tag' && terms.name LIKE '%$token%')";

				if ($i < count($search_terms)){
					$tag_search .= " || ";
				}

			}

		} else {

			$st = mysql_real_escape_string($search_terms[0]);

			//@MD Tag Search
			$tag_search .= "EXISTS (SELECT
										 terms.name
										FROM wp_term_relationships term_rel
										LEFT JOIN wp_term_taxonomy term_tax
										 ON term_tax.term_taxonomy_id = term_rel.term_taxonomy_id
										LEFT JOIN wp_terms terms
										 ON term_tax.term_id = terms.term_id
										WHERE object_id = post.ID &&
										 term_tax.taxonomy = 'post_tag' && terms.name LIKE '%$st%')";

			$new_search_terms = $st;
		}

		//blogs
		$conditions = " WHERE (MATCH(post_search.post_content,post_search.post_title) AGAINST ('$new_search_terms' IN BOOLEAN MODE) || ($tag_search) ) && post.post_status = 'publish' && post.post_type = 'post' && (term_tax.description != '' && terms.name NOT LIKE '%sample%') $platform_conditions";

		//blogs that 
		$limit = " LIMIT $offset,$num";
		$sql = "SELECT
					post.ID,
					post.post_author,
					post.post_date,
					post.post_title,
					LEFT(post.post_content, 240) As post_content,
					post.post_name,
					post.post_type,
					post.comment_count,
					post.comment_status,
					MATCH (post_search.post_content,post_search.post_title) AGAINST ('$new_search_terms') AS score
				FROM wp_posts post
				LEFT JOIN wp_term_relationships term_rel
				 	ON post.ID = term_rel.object_id
				LEFT JOIN wp_term_taxonomy term_tax
				 	ON term_rel.term_taxonomy_id = term_tax.term_taxonomy_id
				LEFT JOIN wp_terms terms
 					ON term_tax.term_id = terms.term_id
				LEFT JOIN wp_posts_fulltext_search post_search
				 	ON post.ID=post_search.post_id
				$conditions
				GROUP BY post.ID
				ORDER BY $search_type DESC
				$limit";

		$results = $wpdb->get_results($sql);



		// add categories to returned results and sort by relevance
		$blog_posts = $this->get_blog_categories_tags($results);

		return $blog_posts;

	}

	/**
	 * get_blog_sample_results
	 *
	 * Return Post with Category of Samples Only!
	 *
	 * @param 	array 	$platforms
	 * @param 	string 	$search_terms
	 * @param 	string 	$advanced_search_type
	 * @param 	int 	$offset
	 * @param 	int 	$num
	 * @return 	array	$sample_results
	 *
	 */
	private function get_blog_sample_results($platforms, $search_terms, $advanced_search_type, $offset, $num){
		
		global $wpdb;

		// check $offset and $num are integers
		if (!is_int($offset) || !is_int($num)){
			die("Error. Paging must have integers!");
		}

		if ($advanced_search_type == 'date'){
			$search_type = "post.post_date";
		} else {
			$search_type = "score";
		}

		//@MD Magic Quotes are switched on. Remove slashes before doing regex. We will escape array values after. Search terms are split and place in an array.
		$search_terms = stripslashes(stripslashes($search_terms));
		$search_terms = preg_split('#\s*("[^"]*")\s*|\s+#', $search_terms, -1 , PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

		if(count($search_terms) > 1){

			$i = 0;

			foreach($search_terms As $token){
		
				$token = mysql_real_escape_string($token);

				$i++;
				
				$new_search_terms .= " +" . $token;

			}

		} else {

			$st = mysql_real_escape_string($search_terms[0]);

			$new_search_terms = $st;
		}

		$sql = "SELECT 
					s.id, 
					s.slug, 
					s.name, 
					s.title, 
					s.description, 
					s.created, 
					s.modified, 
					s.dotnet_version, 
					s.language, 
					s.platform, 
					MATCH(s.title,s.description) AGAINST('$new_search_terms') AS score 
				FROM sl_samples s 
				JOIN sl_samples_tags st 
				ON s.id = st.sample_id 
				JOIN sl_tags t 
				ON st.tag_id = t.id 
				WHERE (MATCH(s.title,s.description) AGAINST('$new_search_terms' IN BOOLEAN MODE) || 
					(EXISTS (
						SELECT 
							t.title 
						FROM sl_samples_tags st 
						JOIN sl_tags t 
						ON t.id = st.tag_id 
						WHERE st.sample_id = s.id && 
							t.title LIKE '%$new_search_terms%'
					)
				)) 
				GROUP BY s.title 
				ORDER BY s.modified DESC 
				LIMIT $offset,$num";

		$results = $wpdb->get_results($sql);

		// add categories to returned results and sort by relevance
		$sample_results = $this->get_sl_tags($results);

		return $sample_results;

	}

	/**
	 * get_sl_tags
	 *
	 * Get sample tags
	 *
	 * @param 	array 	$results
	 * @return 	array	$samples
	 *
	 */
	private function get_sl_tags($results){

		global $wpdb;

		foreach($results As $sample){

			$sample_id = $sample->id;

			$sql = "SELECT t.title FROM sl_tags t JOIN sl_samples_tags st ON t.id = st.tag_id WHERE st.sample_id = $sample_id";

    		$tags = $wpdb->get_results($sql, ARRAY_A);

			$samples[] = array(
								"id" => $sample->id,
								"slug" => $sample->slug,
								"name" => $sample->name,
								"title" => $sample->title,
								"created" => $sample->created,
								"modified" => $sample->modified,
								"dotnet_version" => $sample->dotnet_version,
								"tags" => $tags
							);
		
		}

		return $samples;

	}

	/**
	 * get_blog_categories_tags
	 *
	 * Get blog categories and tags
	 *
	 * @param 	array 	$results
	 * @return 	array	$blog_posts
	 *
	 */
	private function get_blog_categories_tags($results){

		global $wpdb;
		
		// new array for re-sort
		$blog_posts = array();

		foreach($results As $blog){
			
			$blog_id = $blog->ID;

			$sql = "SELECT
					 terms.name,
					 terms.slug
					FROM wp_term_relationships term_rel
					LEFT JOIN wp_term_taxonomy term_tax
					 ON term_tax.term_taxonomy_id = term_rel.term_taxonomy_id
					LEFT JOIN wp_terms terms
					 ON term_tax.term_id = terms.term_id
					WHERE object_id = $blog_id &&
					 term_tax.taxonomy = 'category'";
	
			$categories = $wpdb->get_results($sql);

			$sql = "SELECT
					 terms.name,
					 terms.slug
					FROM wp_term_relationships term_rel
					LEFT JOIN wp_term_taxonomy term_tax
					 ON term_tax.term_taxonomy_id = term_rel.term_taxonomy_id
					LEFT JOIN wp_terms terms
					 ON term_tax.term_id = terms.term_id
					WHERE object_id = $blog_id &&
					 term_tax.taxonomy = 'post_tag'";
			
			$tags = $wpdb->get_results($sql);

			if (empty($tags)){
				$tags = array("NO TAGS");
			}

			$blog_posts[] = array(
								"categories" => $categories,
								"tags" => $tags,
								"ID" => $blog->ID,
								"post_author" => $blog->post_author,
								"post_date" => $blog->post_date,
								"post_title" => $blog->post_title,
								"post_content" => $blog->post_content,
								"post_name" => $blog->post_name,
								"post_type" => $blog->post_type,
								"comment_count" => $blog->comment_count,
								"comment_status" => $blog->comment_status,
								"score" => $blog->score
							);

		}
		
		return $blog_posts;

	}

	/**
	 * human_timing
	 *
	 * Returns time in more readable format.
	 *
	 * @param 	string 	$time
	 * @return 	string
	 *
	 */
	function human_timing($time){

		$time = time() - $time; // to get the time since that moment

		$tokens = array (
					31536000 	=> 'year',
					2592000 	=> 'month',
					604800 		=> 'week',
					86400 		=> 'day',
					3600 		=> 'hour',
					60 			=> 'minute',
					1 			=> 'second'
				);

		foreach ($tokens as $unit => $text) {

			if ($time < $unit) continue;
			$numberOfUnits = floor($time / $unit);
			return $numberOfUnits.' '.$text.(($numberOfUnits>1)?'s':'');

		}

	}

	/**
	 * pagination
	 *
	 * Echos out pagination links
	 *
	 * @param 	string 	$jsfunction
	 * @param 	string 	$view_start
	 * @param 	string 	$view_end
	 * @param 	int 	$total_count
	 * @param 	int 	$total_pages
	 * @param 	int 	$page
	 * @param 	int 	$range
	 *
	 */
	function pagination($jsfunction,$view_start,$view_end,$total_count,$total_pages,$page,$range){

		echo "<div id='pag-top' class='pagination-search-container'>
			<div class='pag-count' id='topic-count-top'>Viewing Posts " . $view_start . " to " . $view_end . " (" . $total_count . " total posts)<span class='ajax-loader'></span></div>
				<div class='pagination-search'>";

		if ($page > 1){
			


			echo " <a href='javascript:" . $jsfunction . "(1)'><<</a> ";

			$prevpage = $page - 1;

			echo " <a href='javascript:" . $jsfunction . "($prevpage)'><</a> ";

		}

		if ($total_pages > 1){
			
			for ($x = ($page - $range); $x < (($page + $range) + 1); $x++){
		 
			 if (($x > 0) && ($x <= $total_pages)) {
			 
			 if ($x == $page) {
			 
			 echo " <b>$x</b> ";
			 
			 } else {
			 // make it a link
			 echo " <a href='javascript:" . $jsfunction . "($x)'>$x</a>";
			 }
			 }
			}

		}

		if ($page != $total_pages) {
		 
		 $nextpage = $page + 1;
		 
		 echo " <a href='javascript:" . $jsfunction . "($nextpage)'>></a> ";
		 echo " <a href='javascript:" . $jsfunction . "($total_pages)'>>></a> ";

		}

		echo "</div></div>";

	}

	/**
	 * forum_pagination
	 *
	 * Echos out forum pagination links
	 *
	 * @param 	string 	$view_start
	 * @param 	string 	$view_end
	 * @param 	int 	$total_count
	 * @param 	int 	$total_pages
	 * @param 	int 	$page
	 * @param 	int 	$range
	 *
	 */
	function forum_pagination($view_start,$view_end,$total_count,$total_pages,$page,$range){

		$forums = 'forums';

		echo "<div id='pag-top' class='pagination-search-container'>
			<div class='pag-count' id='topic-count-top'>Viewing Posts " . $view_start . " to " . $view_end . " (" . $total_count . " total posts)<span class='ajax-loader'></span></div>
				<div class='pagination-search'>";

		if ($page > 1){
			echo " <a href='javascript:forum_paging(1)'><<</a> ";

			$prevpage = $page - 1;

			if ($platforum)

			echo " <a href='javascript:forum_paging($prevpage)'><</a> ";

		}

		if ($total_pages > 1){
			
			for ($x = ($page - $range); $x < (($page + $range) + 1); $x++){
		 
			 if (($x > 0) && ($x <= $total_pages)) {
			 
			 if ($x == $page) {
			 
			 echo " <b>$x</b> ";
			 
			 } else {
			 	
			 	// make it a link
			 	echo " <a href='javascript:forum_paging($x)'>$x</a>";
			 }
			 }
			}

		}

		if ($page != $total_pages) {
		 
		 $nextpage = $page + 1;
		 
		 echo " <a href='javascript:forum_paging($nextpage)'>></a> ";
		 echo " <a href='javascript:forum_paging($total_pages)'>>></a> ";

		}

		echo "</div></div>";

	}

	/**
	 * truncate_str
	 *
	 * Truncates string with more than a set number of characters while not cutting off words.
	 *
	 * @param 	string 	$str
	 * @param 	int 	$maxlen
	 * @return 	string
	 *
	 */
	private function truncate_str($str, $maxlen){

		if ( strlen($str) <= $maxlen ){
			return $str;
		}
	
		$newstr = substr($str, 0, $maxlen);

		if ( substr($newstr,-1,1) != ' ' ){
			$newstr = substr($newstr, 0, strrpos($newstr, " "));
		}

		return $newstr . "...";
	}

	/**
	 * get_sections
	 *
	 * Returns sections. This is currently hardcoded. Awaiting admin area.
	 *
	 * @return 	array $sections
	 *
	 */
	function get_sections(){
		
		$sections = array();

		$sections[0] = "Blogs";
		$sections[1] = "Forums";
		$sections[2] = "Samples";

		return $sections;

	}

	/**
	 * get_platforms
	 *
	 * Returns platforms.
	 *
	 * @return 	array $results
	 *
	 */
	function get_platforms(){
		
		global $wpdb;

		$sql = "SELECT id,name FROM search_categorys search_cat ORDER BY weight ASC";
		$results = $wpdb->get_results($sql);

		return $results;
		
	}

	/**
	 * get_forums_list
	 *
	 * Returns forums id and name in an array
	 *
	 * @param 	int 	$platform
	 * @return 	array 	$sections
	 *
	 */
	function get_forums_list($platform){
		
		global $wpdb;

		$sql = "SELECT
				 grp2.id,
				 grp2.name
				FROM wp_bp_groups grp1
				LEFT JOIN wp_bp_groups grp2
				 ON grp2.parent_id = grp1.id
				WHERE grp1.id = '$platform'
				ORDER BY grp2.name";
		$results = $wpdb->get_results($sql);

		return $results;

	}

	/**
	 * get_forum_platform
	 *
	 * Returns platform id
	 *
	 * @param 	string 	$slug
	 * @return 	int 	$platform
	 *
	 */
	function get_forum_platform($slug){
		
		global $wpdb;

		$sql = "SELECT
				  search_meta.category_id
				FROM search_meta search_meta
				LEFT JOIN wp_bp_groups groups
				  ON groups.id = search_meta.meta_value
				WHERE search_meta.meta_key = 'forum_id' &&
				  groups.slug = '$slug'";
	
		$platform = $wpdb->get_var($sql);
		
		return $platform;

	}

	/**
	 * get_sample_platform
	 *
	 * Returns sample platform id
	 *
	 * @param 	string 	$slug
	 * @return 	int 	$platform
	 *
	 */
	function get_sample_platform($slug){
		
		global $wpdb;

		$sql = "SELECT
				  search_cat.id
				FROM search_meta
				LEFT JOIN search_categorys search_cat
				  ON search_meta.category_id = search_cat.id
				LEFT JOIN wp_terms
				  ON search_meta.meta_value = wp_terms.term_id
				WHERE meta_key = 'sample_id' &&
				  wp_terms.slug = '$slug'";
		
		$platform = $wpdb->get_var($sql);
		
		return $platform;

	}

	/**
	 * get_blog_platform
	 *
	 * Returns sample platform id
	 *
	 * @param 	string 	$slug
	 * @return 	int 	$platform
	 *
	 */
	function get_blog_platform($slug){
		
		global $wpdb;

		$sql = "SELECT
				  search_cat.id
				FROM search_meta
				LEFT JOIN search_categorys search_cat
				  ON search_meta.category_id = search_cat.id
				LEFT JOIN wp_terms
				  ON search_meta.meta_value = wp_terms.term_id
				WHERE meta_key = 'blog_id' &&
				  wp_terms.slug = '$slug'";
		
		$platform = $wpdb->get_var($sql);
		
		return $platform;

	}

	/**
	 * find_section_platform
	 *
	 * Returns section and category id
	 *
	 * @param 	string 	$slug
	 * @return 	array 	$results
	 *
	 */
	function find_section_platform($slug){
		
		global $wpdb;

		$sql = "SELECT
				  search_meta.meta_key,
				  search_meta.category_id
				FROM wp_posts posts
				LEFT JOIN wp_term_relationships term_rel
				  ON posts.ID = term_rel.object_id
				LEFT JOIN wp_term_taxonomy term_tax
				  ON term_rel.term_taxonomy_id = term_tax.term_taxonomy_id
				LEFT JOIN search_meta search_meta
				  ON term_tax.term_id = search_meta.meta_value
				WHERE posts.post_name = '$slug' &&
				  term_tax.taxonomy = 'category'";

		$results = $wpdb->get_results($sql);
		
		return $results;

	}

	/**
	 * url_gatekeeper
	 *
	 * Used to decode the referer path for page specific search 
	 *
	 * @param 	string 	$referrer URL
	 * @return 	array 	$ref_details
	 *
	 */
	function url_gatekeeper($referer){
		
		$referer_parts = explode("/", $referer);

		// @MD - Please be really careful changing this switch statement! Each case and if block are reading referer URLs
		switch($referer_parts[3]){
			case 'groups':

				$ref_details['section'] = 'forums';

				if ($referer_parts[4] != ''){
					$ref_details['platform'] = $this->get_forum_platform($referer_parts[4]);
				}

				break;
			case 'posts':

				$ref_details['section'] = 'blogs';
				
				break;

			case 'samples':

				$ref_details['section'] = 'samples';

				break;

			case 'topics':

				//echo $referer_parts[4];

				// sub-dir contains sample in string
				if (strlen(strstr($referer_parts[4],'sample'))>0) {

					$ref_details['section'] = 'samples';

					// subdir matches exactly 'samples' then get its subdir for platform
					if ($referer_parts[4] == 'samples'){
						$ref_details['platform'] = $this->get_sample_platform($referer_parts[5]);
					} else {
						$ref_details['platform'] = $this->get_sample_platform($referer_parts[4]);
					}


				} else {
					
					// if sample substr not in subdir then assume from category that its a blog post

					$ref_details['section'] = 'blogs';
					$ref_details['platform'] = $this->get_blog_platform($referer_parts[4]);


				}

				break;

			default:

				// if subdir is numeric, then its either a blog or sample post.
				if (is_numeric($referer_parts[3])){
					
					$section_platform = $this->find_section_platform($referer_parts[6]);

					if ($section_platform[0]->meta_key == 'blog_id'){
						$ref_details['section'] = 'blogs';
					} else if ($section_platform[0]->meta_key == 'sample_id'){
						$ref_details['section'] = 'samples';
					}

					$ref_details['platform'] = $section_platform[0]->category_id;

				}

				break;

		}

		return $ref_details;

	}

}

add_action('ultimate-search', 'ultimatesearch_js_header' );

	/**
	 * ultimatesearch_js_header
	 *
	 * Hooks the JavaScript to the header of the page
	 *
	 * @param 	string 	$search_terms
	 *
	 */
	function ultimatesearch_js_header($search_terms){

		$search = new UltimateSearch();

		$search_terms 	= $_POST['search-terms'];



?>
<script type="text/javascript">
//<![CDATA[

function forum_parent(){
	
	var slug 			= slug;
	var platform_id 	= platform_id;

}

$(document).ready(function(){

	var advanced_search_terms 	= "";
	var section_list = new Array();
	var platform_list = new Array();
	var forum_parents = new Array();
	var sections = new Array();
	var platforms = new Array();
	var ref_section = new Array();
	var ref_platform = new Array();

	<?php 

		$ref_details = $search->url_gatekeeper($_SERVER['HTTP_REFERER']);
		$section = $ref_details['section'];
		$platform = $ref_details['platform'];

		echo "ref_section.push('$section')\n";
		echo "ref_platform.push($platform)\n";

		if ($section == 'forums'){
			
			echo "$('.community-blogs-results-container').hide();\n";
			echo "$('.community-samples-results-container').hide();\n";

			echo "load_search_advanced(0,ref_section,ref_platform,null);\n";
			echo "load_forums(1,ref_platform,null,null);\n";

		} else if ($section == 'samples'){
			
			echo "$('.community-blogs-results-container').hide();\n";
			echo "$('.community-forums-results-container').hide();\n";

			echo "load_search_advanced(0,ref_section,ref_platform,null);\n";
			echo "load_samples(1,ref_platform,null,null);\n";

		} else if ($section == 'blogs'){
			
			echo "$('.community-samples-results-container').hide();\n";
			echo "$('.community-forums-results-container').hide();\n";

			echo "load_search_advanced(0,ref_section,ref_platform,null);\n";
			echo "load_blogs(1,ref_platform,null,null);\n";

		} else {
			
			echo "load_search_advanced(0,'blogs','null',null);\n";
			echo "load_samples(1,null,null);\n";
			echo "load_blogs(1,null,null);\n";
			echo "load_forums(1,null,null,null);\n";

		}


	?>


	<?php

		$search 		= new UltimateSearch();
		$section_list 	= $search->get_sections();
		//$platform_list 	= $search->get_platforms();

		$i = 0;
		foreach($section_list as $section_item){
			
			echo "section_list[$i] = '" . strtolower($section_item) . "'\n\t";

			$i++;
		}


	?>

		
	// on page load request results!
	$('#wijdialog').wijdialog({
        autoOpen: false,
        width: 600,
        captionButtons: {
                pin: { visible: false },
                refresh: { visible: false },
                toggle: { visible: false },
                minimize: { visible: false },
                maximize: { visible: false }
        },
        buttons: {
            "Ok": function () {
                $(this).wijdialog("close");
            },
            "Cancel": function () {
                $(this).wijdialog("close");
            }
        }
    });



	$('#btn-advanced-search').live('click', function(){

		var selected_sections = new Array();
		var selected_platforms = new Array();

		var advanced_search_terms 	= $("input[name='advanced_search_terms']").val();
		var advanced_search_type 	= $("input[name='advanced_search_type']:checked").val();
			
		$.each($("input[name='check_search_section']:checked"), function() {
			selected_sections.push($(this).val());
		});

		/*if(advanced_search_terms == ''){
			 $("#wijdialog").html("Please enter search terms.");
			 $('#wijdialog').wijdialog('open');
             return false;
		}*/
		var trigger_triggered = false;

		if(selected_sections.length < 1){
			$('.search-advanced-tooltip').wijtooltip("show");
			$("#filter-options a").trigger('click');
			return false;
		}

		$.each($("input[name='check_search_platform']:checked"), function() {
			selected_platforms.push($(this).val());
		});

		load_search_advanced(1,selected_sections,selected_platforms,advanced_search_terms,advanced_search_type);

		// hide everything
		$.each(section_list, function(index, value) {
		  	$('.community-' + value + '-results-container').hide();
		});

		// show selected sections
		$.each(selected_sections, function(index, value) {
		  	$('.community-' + value + '-results-container').show();
		  	$('#community-' + value + '-results').html("<img class='loading' src='<?php bloginfo( "wpurl" ); ?>/wp-content/themes/wijmo/images/ajax-loader2.gif' />");
		 	window['load_'+value](1,selected_platforms,advanced_search_terms,advanced_search_type);
		});


	});

	<?php 
	
		if ($_REQUEST['advanced'] == 1){
			
			echo "
				$('#search-results').addClass('search-advanced-margin');
				$('.search-advanced-container').wijexpander({
					expanded: true
				});
				$('#search-header').hide();
				$('#btn-advanced-search-show').hide();
				$('.community-blogs-results-container').hide();
				$('.community-forums-results-container').hide();
				$('.members-search-result-container').hide();
				$('.community-samples-results-container').hide();
				$('.search-advanced-container').show();
			";

		}

	?>

});

	/**
	 * load_search_advanced
	 *
	 * JavaScript function
	 *
	 *
	 */
	function load_search_advanced(advanced,sections,platforms,advanced_search_terms,advanced_search_type){

		//@MD check if adv_search_terms input has been passed
		if (advanced_search_terms){
			search_terms = advanced_search_terms;
		} else {
			search_terms = '<?php echo $search_terms; ?>';
		}

		$.ajax({
		 type: 'POST',
		 url: "<?php bloginfo( 'wpurl' ); ?>/wp-content/plugins/c1-ultimate-search/search-advanced.php",
		 data: { advanced: advanced, search_terms: search_terms, sections: sections, platforms: platforms, advanced_search_type: advanced_search_type },
		 success: function(data){ 
			 	$("#search-advanced").html(data);
			}
		});

	}

	/**
	 * load_samples
	 *
	 * JavaScript function
	 *
	 *
	 */
	function load_samples(page,platforms,advanced_search_terms,advanced_search_type){

		//@MD check if adv_search_terms input has been passed
		if (advanced_search_terms){
			search_terms = advanced_search_terms;
		} else {
			search_terms = '<?php echo $search_terms; ?>';
		}

		if (platforms == null){
			platforms = '';
		}

		$.ajax({
		 type: 'POST',
		 url: "<?php bloginfo( 'wpurl' ); ?>/wp-content/plugins/c1-ultimate-search/blogs_samples.php?page=" + page,
		 data: { search_terms: search_terms, platforms: platforms, advanced_search_type: advanced_search_type },
		 success: function(data){ 
			$("#community-samples-results").html(data);
			}
		});

	}

	/**
	 * load_blogs
	 *
	 * JavaScript function
	 *
	 *
	 */
	function load_blogs(page,platforms,advanced_search_terms,advanced_search_type){
		
		//@MD check if adv_search_terms input has been passed
		if (advanced_search_terms){
			search_terms = advanced_search_terms;
		} else {
			search_terms = '<?php echo $search_terms; ?>';
		}

		if (platforms == null){
			platforms = '';
		}

		$.ajax({
		 type: 'POST',
		 url: "<?php bloginfo( 'wpurl' ); ?>/wp-content/plugins/c1-ultimate-search/blogs.php?page=" + page,
		 data: { search_terms: search_terms, platforms: platforms, advanced_search_type: advanced_search_type },
		 success: function(data){ 
			 $("#community-blogs-results").html(data);
			}
		});

	}

	/**
	 * load_blogs
	 *
	 * JavaScript function
	 *
	 *
	 */
	function load_forums(page,platforms,advanced_search_terms,advanced_search_type){
		
		//@MD check if adv_search_terms input has been passed
		if (advanced_search_terms){
			search_terms = advanced_search_terms;
		} else {
			search_terms = '<?php echo $search_terms; ?>';
		}

		if (platforms == null){
			platforms = '';
		}

		$.ajax({
		 type: 'POST',
		 url: "<?php bloginfo( 'wpurl' ); ?>/wp-content/plugins/c1-ultimate-search/forums.php?page=" + page,
		 data: { search_terms: search_terms, platforms: platforms, advanced_search_type: advanced_search_type },
		 success: function(data){ 
			 $("#community-forums-results").html(data);
			}
		});

	}

	/**
	 * forum_paging
	 *
	 * JavaScript function
	 *
	 *
	 */
	function forum_paging(page){
		
		var selected_sections = new Array();
		var selected_platforms = new Array();

		var advanced_search_terms 	= $("input[name='advanced_search_terms']").val();
		var advanced_search_type 	= $("input[name='advanced_search_type']:checked").val();

		$.each($("input[name='check_search_section']:checked"), function() {
			selected_sections.push($(this).val());
		});

		$.each($("input[name='check_search_platform']:checked"), function() {
			selected_platforms.push($(this).val());
		});

		load_forums(page,selected_platforms,advanced_search_terms,advanced_search_type);

	}

	/**
	 * load_blogs
	 *
	 * JavaScript function
	 *
	 *
	 */
	function sample_paging(page){
		
		var selected_sections = new Array();
		var selected_platforms = new Array();

		var advanced_search_terms 	= $("input[name='advanced_search_terms']").val();
		var advanced_search_type 	= $("input[name='advanced_search_type']:checked").val();

		$.each($("input[name='check_search_section']:checked"), function() {
			selected_sections.push($(this).val());
		});

		$.each($("input[name='check_search_platform']:checked"), function() {
			selected_platforms.push($(this).val());
		});

		load_samples(page,selected_platforms,advanced_search_terms,advanced_search_type);

	}

	/**
	 * blog_paging
	 *
	 * JavaScript function
	 *
	 *
	 */
	function blog_paging(page){
		
		var selected_sections = new Array();
		var selected_platforms = new Array();

		var advanced_search_terms 	= $("input[name='advanced_search_terms']").val();
		var advanced_search_type 	= $("input[name='advanced_search_type']:checked").val();

		$.each($("input[name='check_search_section']:checked"), function() {
			selected_sections.push($(this).val());
		});

		$.each($("input[name='check_search_platform']:checked"), function() {
			selected_platforms.push($(this).val());
		});

		load_blogs(page,selected_platforms,advanced_search_terms,advanced_search_type);

	}

//]]>
</script>

<?php

}

?>