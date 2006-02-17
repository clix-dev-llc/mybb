<?php
/**
 * MyBB 1.0
 * Copyright � 2005 MyBulletinBoard Group, All Rights Reserved
 *
 * Website: http://www.mybboard.com
 * License: http://www.mybboard.com/eula.html
 *
 * $Id$
 */

$templatelist = "newreply,previewpost,error_invalidforum,error_invalidthread,redirect_threadposted,loginbox,changeuserbox,posticons,newreply_threadreview,forumrules,attachments,newreply_threadreview_post";
$templatelist .= ",smilieinsert,codebuttons,post_attachments_new,post_attachments,post_savedraftbutton,newreply_modoptions";

require "./global.php";
require "./inc/functions_post.php";
require "./inc/functions_user.php";
require "./inc/class_parser.php";
$parser = new postParser;
// Load global language phrases
$lang->load("newreply");

$pid = intval($mybb->input['pid']);
$tid = intval($mybb->input['tid']);

if($mybb->input['action'] == "editdraft" || ($mybb->input['savedraft'] && $pid) || ($tid && $pid))
{
	$query = $db->query("SELECT * FROM ".TABLE_PREFIX."posts WHERE pid='$pid'");
	$post = $db->fetch_array($query);
	if(!$post['pid'])
	{
		error($lang->error_invalidpost);
	}
	$tid = $post['tid'];
}
$query = $db->query("SELECT * FROM ".TABLE_PREFIX."threads WHERE tid='$tid'");
$thread = $db->fetch_array($query);
$fid = $thread['fid'];
$query = $db->query("SELECT * FROM ".TABLE_PREFIX."forums WHERE fid='$fid' AND active!='no'");
$forum = $db->fetch_array($query);

// Make navigation
makeforumnav($fid);
$thread['subject'] = htmlspecialchars_uni($thread['subject']);
addnav($thread['subject'], "showthread.php?tid=$thread[tid]");
addnav($lang->nav_newreply);

$forumpermissions = forum_permissions($fid);

if(isset($post) && (($post['visible'] == 0 && ismod($fid) != "yes") || $post['visible'] < 0))
{
	error($lang->error_invalidpost);
}
if(!$thread['subject'] || (($thread['visible'] == 0 && ismod($fid) != "yes") || $thread['visible'] < 0))
{
	error($lang->error_invalidthread);
}
if($forum['open'] == "no" || $forum['type'] != "f")
{
	error($lang->error_closedinvalidforum);
}
if($forumpermissions['canview'] == "no" || $forumpermissions['canpostreplys'] == "no")
{
	nopermission();
}
// Password protected forums ......... yhummmmy!
checkpwforum($fid, $forum['password']);

if($mybb->settings['bbcodeinserter'] != "off" && $forum['allowmycode'] != "no" && (!$mybb->user['uid'] || $mybb->user['showcodebuttons'] != 0))
{
	$codebuttons = makebbcodeinsert();
	if($forum['allowsmilies'] != "no")
	{
		$smilieinserter = makesmilieinsert();
	}
}

if($mybb->user['uid'] != 0)
{
	eval("\$loginbox = \"".$templates->get("changeuserbox")."\";");
}
else
{
	if(!$mybb->input['previewpost'] && $mybb->input['action'] != "do_newreply")
	{
		$username = "Guest";
	}
	elseif($mybb->input['previewpost'])
	{
		$username = $mybb->input['username'];
	}
	eval("\$loginbox = \"".$templates->get("loginbox")."\";");
}
// check to see if the threads closed, and if the user is a mod
if(ismod($fid, "caneditposts") != "yes")
{
	if($thread['closed'] == "yes")
	{
		redirect("showthread.php?tid=$tid", $lang->redirect_threadclosed);
	}
}

if($mybb->input['action'] != "do_newreply" && $mybb->input['action'] != "editdraft")
{
	$mybb->input['action'] = "newreply";
}

if($mybb->input['previewpost'])
{
	$mybb->input['action'] = "newreply";
}
if(!$mybb->input['removeattachment'] && ($mybb->input['newattachment'] || ($mybb->input['action'] == "do_newreply" && $mybb->input['submit'] && $_FILES['attachment'])))
{
	// If there's an attachment, check it and upload it
	if($_FILES['attachment']['size'] > 0 && $forumpermissions['canpostattachments'] != "no")
	{
		require_once "./inc/functions_upload.php";
		$attachedfile = upload_attachment($_FILES['attachment']);
	}
	if($attachedfile['error'])
	{
		eval("\$attacherror = \"".$templates->get("error_attacherror")."\";");
		$mybb->input['action'] = "newreply";
	}
	if(!$mybb->input['submit'])
	{
		$mybb->input['action'] = "newreply";
	}
}
if($mybb->input['removeattachment'])
{ // Lets remove the attachment
	require_once "./inc/functions_upload.php";
	remove_attachment($pid, $mybb->input['posthash'], $mybb->input['removeattachment']);
	if(!$mybb->input['submit'])
	{
		$mybb->input['action'] = "newreply";
	}
}

// Max images check
if($mybb->input['action'] == "do_newreply" && !$mybb->input['savedraft'])
{
	if($mybb->settings['maxpostimages'] != 0 && $mybb->usergroup['cancp'] != "yes")
	{
		if($mybb->input['postoptions']['disablesmilies'] == "yes")
		{
			$allowsmilies = "no";
		}
		else
		{
			$allowsmilies = $forum['allowsmilies'];
		}
		$imagecheck = postify($mybb->input['message'], $forum['allowhtml'], $forum['allowmycode'], $allowsmilies, $forum['allowimgcode']);
		if(substr_count($imagecheck, "<img") > $mybb->settings['maxpostimages'])
		{
			eval("\$maximageserror = \"".$templates->get("error_maxpostimages")."\";");
			$mybb->input['action'] = "newreply";
		}
	}
}

// Setup our posthash for managing attachments
if(!$mybb->input['posthash'] && $mybb->input['action'] != "editdraft")
{
	mt_srand ((double) microtime() * 1000000);
	$mybb->input['posthash'] = md5($thread['tid'].$mybb->user['uid'].mt_rand());
}

if($mybb->input['action'] == "newreply" || $mybb->input['action'] == "editdraft")
{
	$plugins->run_hooks("newreply_start");

	if($pid && !$mybb->input['previewpost'] && $mybb->input['action'] != "editdraft")
	{
		$query = $db->query("SELECT p.*, u.username FROM ".TABLE_PREFIX."posts p LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=p.uid) WHERE p.pid='$pid' AND p.tid='$tid' AND p.visible='1'");
		$quoted = $db->fetch_array($query);
		$quoted['subject'] = preg_replace("#RE:#i", '', $quoted['subject']);
		$subject = "RE: ".$quoted['subject'];
		$quoted['message'] = preg_replace('#^/me (.*)$#im', "* $quoted[username] \\1", $quoted['message']);
		if($quoted['username'])
		{
			$message = "[quote=$quoted[username]]\n$quoted[message]\n[/quote]";
		}
		else
		{
			$message = "[quote]\n$quoted[message]\n[/quote]";
		}
		// Remove [attachment=x] from quoted posts.
		$message = preg_replace("#\[attachment=([0-9]+?)\]#i", '', $message);
	}
	if($mybb->input['previewpost'])
	{
		$previewmessage = $mybb->input['message'];
	}
	if(!$message)
	{
		$message = $mybb->input['message'];
	}
	$message = htmlspecialchars_uni($message);
	$editdraftpid = '';

	if($mybb->input['previewpost'] || $maximageserror)
	{
		$postoptions = $mybb->input['postoptions'];
		if($postoptions['signature'] == "yes")
		{
			$postoptionschecked['signature'] = "checked";
		}
		if($postoptions['emailnotify'] == "yes")
		{
			$postoptionschecked['emailnotify'] = "checked";
		}
		if($postoptions['disablesmilies'] == "yes")
		{
			$postoptionschecked['disablesmilies'] = "checked";
		}
		$subject = $mybb->input['subject'];
	}
	elseif($mybb->input['action'] == "editdraft" && $mybb->user['uid'])
	{
		$message = htmlspecialchars_uni($post['message']);
		$subject = $post['subject'];
		if($post['includesig'] != "no")
		{
			$postoptionschecked['signature'] = "checked";
		}
		if($post['smilieoff'] == "yes")
		{
			$postoptionschecked['disablesmilies'] = "checked";
		}
		$editdraftpid = "<input type=\"hidden\" name=\"pid\" value=\"$pid\" />";
		$mybb->input['icon'] = $post['icon'];
	}
	else
	{
		if($mybb->user['signature'] != '')
		{
			$postoptionschecked['signature'] = "checked";
		}
		if($mybb->user['emailnotify'] == "yes")
		{
			$postoptionschecked['emailnotify'] = "checked";
		}
	}
	if($forum['allowpicons'] != "no")
	{
		$posticons = getposticons();
	}

	if($mybb->input['previewpost'])
	{
		if(!$mybb->input['username'])
		{
			$mybb->input['username'] = "Guest";
		}
		if($mybb->input['username'] && !$mybb->user['uid'])
		{
			$mybb->user = validate_password_from_username($mybb->input['username'], $mybb->input['password']);
		}
		$mybb->input['icon'] = intval($mybb->input['icon']);
		$query = $db->query("SELECT u.*, f.*, i.path as iconpath, i.name as iconname FROM ".TABLE_PREFIX."users u LEFT JOIN ".TABLE_PREFIX."userfields f ON (f.ufid=u.uid) LEFT JOIN ".TABLE_PREFIX."icons i ON (i.iid='".intval($mybb->input['icon'])."') WHERE u.uid='".$mybb->user['uid']."'");
		$post = $db->fetch_array($query);
		if(!$mybb->user['uid'] || !$post['username'])
		{
			$post['username'] = $mybb->input['username'];
		}
		else
		{
			$post['userusername'] = $mybb->user['username'];
			$post['username'] = $mybb->user['username'];
		}
		$post['message'] = $previewmessage;
		$post['subject'] = $subject;
		$post['icon'] = $icon;
		$post['smilieoff'] = $postoptions['disablesmilies'];
		$post['dateline'] = time();

		// Fetch attachments assigned to this post
		if($mybb->input['pid'])
		{
			$attachwhere = "pid='".intval($mybb->input['pid'])."'";
		}
		else
		{
			$attachwhere = "posthash='".addslashes($mybb->input['posthash'])."'";
		}
		$query = $db->query("SELECT * FROM ".TABLE_PREFIX."attachments WHERE $attachwhere");
		while($attachment = $db->fetch_array($query)) {
			$attachcache[0][$attachment['aid']] = $attachment;
		}

		$postbit = makepostbit($post, 1);
		eval("\$preview = \"".$templates->get("previewpost")."\";");
	}
	$subject = htmlspecialchars_uni($subject);

	if(!$pid && !$mybb->input['previewpost'])
	{
		$subject = "RE: " . $thread['subject'];
	}
	// Setup a unique posthash for attachment management
	$posthash = $mybb->input['posthash'];

	$bgcolor = "trow2";
	if($forumpermissions['canpostattachments'] != "no")
	{ // Get a listing of the current attachments, if there are any
		$attachcount = 0;
		if($mybb->input['action'] == "editdraft")
		{
			$attachwhere = "pid='$pid'";
		}
		else
		{
			$attachwhere = "posthash='".addslashes($posthash)."'";
		}
		$attachments = '';
		$query = $db->query("SELECT * FROM ".TABLE_PREFIX."attachments WHERE $attachwhere");
		while($attachment = $db->fetch_array($query))
		{
			$attachment['size'] = getfriendlysize($attachment['filesize']);
			$attachment['icon'] = getattachicon(getextention($attachment['filename']));
			if($forum['allowmycode'] != "no")
			{
				eval("\$postinsert = \"".$templates->get("post_attachments_attachment_postinsert")."\";");
			}
			eval("\$attachments .= \"".$templates->get("post_attachments_attachment")."\";");
			$attachcount++;
		}
		$query = $db->query("SELECT SUM(filesize) AS ausage FROM ".TABLE_PREFIX."attachments WHERE uid='".$mybb->user['uid']."'");
		$usage = $db->fetch_array($query);
		if($usage['ausage'] > ($mybb->usergroup['attachquota']*1000) && $mybb->usergroup['attachquota'] != 0)
		{
			$noshowattach = 1;
		}
		if($mybb->usergroup['attachquota'] == 0)
		{
			$friendlyquota = $lang->unlimited;
		}
		else
		{
			$friendlyquota = getfriendlysize($mybb->usergroup['attachquota']*1000);
		}
		$friendlyusage = getfriendlysize($usage['ausage']);
		$lang->attach_quota = sprintf($lang->attach_quota, $friendlyusage, $friendlyquota);
		if($mybb->settings['maxattachments'] == 0 || ($mybb->settings['maxattachments'] != 0 && $attachcount <= $mybb->settings['maxattachments']) && !$noshowattach)
		{
			eval("\$newattach = \"".$templates->get("post_attachments_new")."\";");
		}
		eval("\$attachbox = \"".$templates->get("post_attachments")."\";");
		$bgcolor = "trow1";
	}

	if($mybb->user['uid'])
	{
		eval("\$savedraftbutton = \"".$templates->get("post_savedraftbutton")."\";");
	}
	if($mybb->settings['threadreview'] != "off")
	{
		if(ismod($fid) == "yes")
		{
			$visibility = "(p.visible='1' OR p.visible='0')";
		}
		else
		{
			$visibility = "p.visible='1'";
		}
		$query = $db->query("SELECT p.*, u.* FROM ".TABLE_PREFIX."posts p LEFT JOIN ".TABLE_PREFIX."users u ON (p.uid=u.uid) WHERE tid='$tid' AND $visibility ORDER BY dateline DESC");
		$numposts = $db->num_rows($query);
		if($numposts > $mybb->settings['postsperpage'])
		{
			$numposts = $mybb->settings['postsperpage'];
			$lang->thread_review_more = sprintf($lang->thread_review_more, $mybb->settings['postsperpage'], $tid);
			eval("\$reviewmore = \"".$templates->get("newreply_threadreview_more")."\";");
		}
		$postsdone = 0;
		$altbg = "trow1";
		$reviewbits = '';
		while($post = $db->fetch_array($query))
		{
			$postsdone++;
			if($postsdone > $numposts)
			{
				continue;
			}
			else
			{
				$reviewpostdate = mydate($mybb->settings['dateformat'], $post['dateline']);
				$reviewposttime = mydate($mybb->settings['timeformat'], $post['dateline']);
				$parser_options = array(
					"allow_html" => $forum['allowhtml'],
					"allow_mycode" => $forum['allowmycode'],
					"allow_smilies" => $forum['allowsmilies'],
					"allow_imgcode" => $forum['allowimgcode']
				);
				if($post['smilieoff'] == "yes")
				{
					$parser_options['allow_smilies'] = "no";
				}

				if($post['visible'] != 1)
				{
					$altbg = "trow_shaded";
				}

				$reviewmessage = $parser->parse_message($post['message'], $parser_options);
				eval("\$reviewbits .= \"".$templates->get("newreply_threadreview_post")."\";");
				if($altbg == "trow1")
				{
					$altbg = "trow2";
				}
				else
				{
					$altbg = "trow1";
				}
			}
			eval("\$threadreview = \"".$templates->get("newreply_threadreview")."\";");
		}
	}
	// Can we disable smilies or are they disabled already?
	if($forum['allowsmilies'] != "no")
	{
		eval("\$disablesmilies = \"".$templates->get("newreply_disablesmilies")."\";");
	}
	else
	{
		$disablesmilies = "<input type=\"hidden\" name=\"postoptions[disablesmilies]\" value=\"no\" />";
	}
	// Show the moderator options
	if(ismod($fid) == "yes")
	{
		if($thread['closed'] == "yes")
		{
			$closecheck = "checked";
		}
		else
		{
			$closecheck = '';
		}
		if($thread['sticky'])
		{
			$stickycheck = "checked";
		}
		else
		{
			$stickycheck = '';
		}
		eval("\$modoptions = \"".$templates->get("newreply_modoptions")."\";");
	}
	$lang->post_reply_to = sprintf($lang->post_reply_to, $thread['subject']);
	$lang->reply_to = sprintf($lang->reply_to, $thread['subject']);

	$plugins->run_hooks("newreply_end");

	eval("\$newreply = \"".$templates->get("newreply")."\";");
	outputpage($newreply);
}
if($mybb->input['action'] == "do_newreply" && $mybb->request_method == "post")
{
	$plugins->run_hooks("newreply_do_newreply_start");

	if($mybb->user['uid'] == 0)
	{
		$username = htmlspecialchars_uni($mybb->input['username']);
		if(username_exists($mybb->input['username']))
		{
			if(!$mybb->input['password'])
			{
				error($lang->error_usernametaken);
			}
			$mybb->user = validate_password_from_username($mybb->input['username'], $mybb->input['password']);
			if(!$mybb->user['uid'])
			{
				error($lang->error_invalidpassword);
			}
			$mybb->input['username'] = $username = $mybb->user['username'];
			mysetcookie("mybbuser", $mybb->user['uid']."_".$mybb->user['loginkey']);
		}
		else
		{
			if(!$username)
			{
				$username = "Guest";
			}
			$author = 0;
		}
	}
	else
	{
		$username = $mybb->user['username'];
	}
	$updatepost = 0;
	
	require_once "inc/datahandler.php";
	require_once "inc/datahandlers/post.php";
	$posthandler = new PostDataHandler();
	
	// Set the post data that came from the input to the $post array.
	$post = array(
		"subject" => $mybb->input['subject'],
		"icon" => $mybb->input['icon'],
		"uid" => $mybb->input['uid'],
		"username" => $mybb->input['username'],
		"message" => $mybb->input['message'],
		"ipaddress" => $mybb->input['ipaddress'],
		"tid" => $mybb->input['tid']
	);
	$post['options'] = array(
		"signature" => $mybb->input['postoptions']['signature'],
		"emailnotify" => $mybb->input['postoptions']['emailnotify'],
		"disablesmilies" => $mybb->input['postoptions']['disablesmilies']
	);
	
	// Now let the post handler do all the hard work.
	if($posthandler->validate_post($post))
	{
		$postinfo = $posthandler->insert_post($post);
		$pid = $postinfo['pid'];
		$visible = $postinfo['visible'];
	}
	else
	{
		$errors = $posthandler->get_errors();
		// Error code to go here.
	}
	
	// Start Subscriptions
	if(!$savedraft)
	{
		$subject = $parser->parse_badwords($thread['subject']);
		$excerpt = $parser->strip_mycode($mybb->input['message']);
		$excerpt = substr($excerpt, 0, $mybb->settings['subscribeexcerpt']).$lang->emailbit_viewthread;
		$query = $db->query("SELECT dateline FROM ".TABLE_PREFIX."posts WHERE tid='$tid' ORDER BY dateline DESC LIMIT 1");
		$lastpost = $db->fetch_array($query);
		$query = $db->query("SELECT u.username, u.email, u.uid, u.language FROM ".TABLE_PREFIX."favorites f, ".TABLE_PREFIX."users u WHERE f.type='s' AND f.tid='$tid' AND u.uid=f.uid AND f.uid!='".$mybb->user['uid']."' AND u.lastactive>'$lastpost[dateline]'");
		while($subscribedmember = $db->fetch_array($query))
		{
			if($subscribedmember['language'] != '' && $lang->languageExists($subscribedmember['language']))
			{
				$uselang = $subscribedmember['language'];
			}
			elseif($mybb->settings['bblanguage'])
			{
				$uselang = $mybb->settings['bblanguage'];
			}
			else
			{
				$uselang = "english";
			}

			if($uselang == $mybb->settings['bblanguage'])
			{
				$emailsubject = $lang->emailsubject_subscription;
				$emailmessage = $lang->email_subscription;
			}
			else
			{
				if(!isset($langcache[$uselang]['emailsubject_subscription']))
				{
					$userlang = new MyLanguage;
					$userlang->setPath("./inc/languages");
					$userlang->setLanguage($uselang);
					$userlang->load("messages");
					$langcache[$uselang]['emailsubject_subscription'] = $userlang->emailsubject_subscription;
					$langcache[$uselang]['email_subscription'] = $userlang->email_subscription;
					unset($userlang);
				}
				$emailsubject =  $langcache[$uselang]['emailsubject_subscription'];
				$emailmessage =  $langcache[$uselang]['email_subscription'];
			}
			$emailsubject = sprintf($emailsubject, $subject);
			$emailmessage = sprintf($emailmessage, $subscribedmember['username'], $username, $mybb->settings['bbname'], $subject, $excerpt, $mybb->settings['bburl'], $tid);
			mymail($subscribedmember['email'], $emailsubject, $emailmessage);
			unset($userlang);
		}
	}

	// Deciding the fate
	if($visible == -2)
	{
		// Draft post
		$lang->redirect_newreply = $lang->draft_saved;
		$url = "usercp.php?action=drafts";
	}
	elseif($visible == 1)
	{
		// Visible post
		$lang->redirect_newreply .= $lang->redirect_newreply_post;
		$url = "showthread.php?tid=$tid&pid=$pid#pid$pid";
		updatethreadcount($tid);
		updateforumcount($fid);
		$cache->updatestats();
	}
	else
	{
		// Moderated post
		$lang->redirect_newreply .= $lang->redirect_newreply_moderation;
		$url = "showthread.php?tid=$tid";
		// Update the unapproved posts count for the current thread and current forum
		$db->query("UPDATE ".TABLE_PREFIX."threads SET unapprovedposts=unapprovedposts+1 WHERE tid='$tid'");
		$db->query("UPDATE ".TABLE_PREFIX."forums SET unapprovedposts=unapprovedposts+1 WHERE fid='$fid'");
	}

	if(!$savedraft)
	{
		$now = time();
		if($forum['usepostcounts'] != "no")
		{
				$queryadd = ",postnum=postnum+1";
		}
		else
		{
			$queryadd = '';
		}
		$db->query("UPDATE ".TABLE_PREFIX."users SET lastpost='$now' $queryadd WHERE uid='".$mybb->user['uid']."'");

		if(function_exists("replyPosted"))
		{
			replyPosted($pid);
		}

		$plugins->run_hooks("newreply_do_newreply_end");
	}
	// Setup the correct ownership of the attachments
	if($mybb->input['posthash'])
	{
		$db->query("UPDATE ".TABLE_PREFIX."attachments SET pid='$pid' WHERE posthash='".addslashes($mybb->input['posthash'])."'");
	}
	redirect($url, $lang->redirect_newreply);
}
?>