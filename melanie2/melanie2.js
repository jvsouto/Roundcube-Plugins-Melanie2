var timeout_sharesmailboxeslist;
var hide_mailviewsplitterv = false;
var mailviewright_left = 0;
var mailviewleft_width = 0;
var mailviewsplitterv_left = 0;
var page_loading;
var current_page_scroll;

$(document).on({
    dblclick: function (e) {
    	if (hide_mailviewsplitterv) {
    		$('#mailview-left').css("width", mailviewleft_width);
    		$('#mailviewsplitterv').css("left", mailviewsplitterv_left);
    		$('#mailview-right').css("left", mailviewright_left);
    		hide_mailviewsplitterv = false;
    	}
    	else {
    		mailviewleft_width = $('#mailview-left').css("width");
    		mailviewsplitterv_left = $('#mailviewsplitterv').css("left");
    		mailviewright_left = $('#mailview-right').css("left");
    		$('#mailview-left').css("width", '0px');
    		$('#mailviewsplitterv').css("left", '3px');
    		$('#mailview-right').css("left", '12px');
    		hide_mailviewsplitterv = true;
    	}
    	
    }
}, "#mailviewsplitterv"); //pass the element as an argument to .on

$('html').click(function() {
	timeout_sharesmailboxeslist = setTimeout(function() {
		var sharemailboxeslist = $("#sharesmailboxeslist");
		if (sharemailboxeslist.hasClass("sharesmailboxesshow")) {
			sharemailboxeslist.addClass("sharesmailboxeshide")
								.removeClass("sharesmailboxesshow");
			$(".button-sharesmailboxes").removeClass("button-selected");
			$("#folderlist-header-m2").removeClass("click");
			$("#folderlist-header-m2 span").removeClass("click");
		}
		var sharemailboxeslist = $("#sharesmailboxeslist-settings");
		if (sharemailboxeslist.hasClass("sharesmailboxesshow")) {
			sharemailboxeslist.addClass("sharesmailboxeshide")
								.removeClass("sharesmailboxesshow");
			$(".button-sharesmailboxes").removeClass("button-selected");
			$("#folderlist-header-m2-settings span").removeClass("click");
			$("#folderlist-header-m2-settings").removeClass("click");
		}
	}, 200);	
});

$(document).on({
    click: function (e) {
    	clearTimeout(timeout_sharesmailboxeslist);
    	e.stopPropagation();
    	var sharemailboxeslist = $("#sharesmailboxeslist");
    	if (sharemailboxeslist.hasClass("sharesmailboxesshow")) {
    		sharemailboxeslist.addClass("sharesmailboxeshide")
    							.removeClass("sharesmailboxesshow");
    		$("#folderlist-header-m2 span").removeClass("click");
    		$("#folderlist-header-m2").removeClass("click");
    		$(".button-sharesmailboxes").removeClass("button-selected");
    	}
    	else {
    		sharemailboxeslist.addClass("sharesmailboxesshow")
    							.removeClass("sharesmailboxeshide");
    		$("#folderlist-header-m2 span").addClass("click");
    		$("#folderlist-header-m2").addClass("click");
    		$(".button-sharesmailboxes").addClass("button-selected");
    	}
    }
}, ".folderlist-header-m2"); //pass the element as an argument to .on

$(document).on({
    click: function (e) {
    	clearTimeout(timeout_sharesmailboxeslist);
    	e.stopPropagation();
    	var sharemailboxeslist = $("#sharesmailboxeslist-settings");
    	if (sharemailboxeslist.hasClass("sharesmailboxesshow")) {
    		sharemailboxeslist.addClass("sharesmailboxeshide")
    							.removeClass("sharesmailboxesshow");
    		$("#folderlist-header-m2-settings span").removeClass("click");
    		$("#folderlist-header-m2-settings").removeClass("click");
    		$(".button-sharesmailboxes").removeClass("button-selected");
    	}
    	else {
    		sharemailboxeslist.addClass("sharesmailboxesshow")
    							.removeClass("sharesmailboxeshide");
    		$("#folderlist-header-m2-settings span").addClass("click");
    		$("#folderlist-header-m2-settings").addClass("click");
    		$(".button-sharesmailboxes").addClass("button-selected");
    	}
    }
}, ".folderlist-header-m2-settings"); //pass the element as an argument to .on

// Changement d'utilisateur sur la page de login
$(document).on({
    click: function (e) {
    	$('#formlogintable .hidden_login_input').removeClass('hidden_login_input');
    	$('.login_div').hide();
    	$('#rcmloginuser').val('');
    	$('#rcmloginuser').focus();
    	$('#rcmloginkeep').removeAttr('checked');
    }
}, "#rcmchangeuserbutton"); //pass the element as an argument to .on


if (window.rcmail) {
	  rcmail.addEventListener('init', function(evt) {
		  // Initialisation de la liste des pages chargées
		  page_loading = {};
		  current_page_scroll = 1;
		  if (rcmail.env['plugin.show_password_change']) {
			  show_password_change(this);
		  }
		  
		  // Gestion de l'url courrielleur
		  var url = window.location.href.split('?')[1];
		  if (url && url.indexOf('_courrielleur') != -1 && url.indexOf('_err=session') != -1) {
		    if (url.indexOf('_courrielleur=1') != -1) {
		      window.location.href = '_task=login&_courrielleur=1';
		    }
		    else if (url.indexOf('_courrielleur=2') != -1) {
		      window.location.href = '_task=login&_courrielleur=2';
        }
		  }
		  
		  if (rcmail.env.task == 'mail' 
			  	&& (!rcmail.env.action || rcmail.env.action == "") 
			  	&& rcmail.env.use_infinite_scroll) {
			  var scroll = false;
			  $('.pagenavbuttons').hide();
			  $('#countcontrols').hide();
				
			  // Gestion du scroll infini
			  $('#messagelistcontainer').scroll(function() {
				  if (($('#messagelistcontainer').scrollTop() > 1 
						  && (($('#messagelistcontainer').scrollTop() + $('#messagelistcontainer').height()) / $('#messagelist').height()) >= 0.95)
						  && current_page_scroll > 1) {
					  // Affichage de la page suivante au bas de la page					  					  
					  var page = current_page_scroll;
					  if (page > 0 && page <= rcmail.env.pagecount && !page_loading[page]) {
						  page_loading[page] = true;
						  var lock = rcmail.set_busy(true, 'loading');
						  var post_data = {};
						  post_data._mbox = rcmail.env.mailbox;
						  post_data._page = page;
						  // also send search request to get the right records
						  if (rcmail.env.search_request)
							  post_data._search = rcmail.env.search_request;
						  rcmail.http_request('list', post_data, lock);						  
					  }
				  }		  
			  });
			  if (!rcmail.env.ismobile) {
				  // Réinitialise les données une fois que la liste est rafraichie
				  rcmail.message_list.addEventListener('clear', function(evt) {
					  page_loading = {};
					  rcmail.env.current_page = 1;
					  current_page_scroll = 2;
				  });
				  rcmail.addEventListener('responseafterlist', function(evt) {
					  if (rcmail.env.use_infinite_scroll) {
						  current_page_scroll = rcmail.env.current_page + 1;
						  rcmail.env.current_page = 1;
					  }
					  rcmail.http_post('plugin.set_current_page', {});
				  });
			  }
		  }
		  else if (rcmail.env.task == 'settings') {
		    // Masquer la skin mobile de l'interface de choix des skins
		    if ($('#rcmfd_skinmelanie2_larry_mobile').length) {
		      $('#rcmfd_skinmelanie2_larry_mobile').parent().parent().parent().parent().hide();
		    }
		  }
		  else if (rcmail.env.task == 'calendar') {
		    // PAMELA - Masquer les champs non utilisés dans Mélanie2
		    $('#edit-url').parent().hide();
		    $('#edit-priority').parent().hide();
		    $('#edit-free-busy').parent().hide();
		    $('#event-free-busy').hide();
		    $('.edit-alarm-buttons').hide();
		    $("#edit-sensitivity option[value='confidential']").remove();
		    $("#edit-recurrence-frequency option[value='RDATE']").remove();
		  }
	  });
	  // MANTIS 0004276: Reponse avec sa bali depuis une balp, quels "Elements envoyés" utiliser
	  rcmail.addEventListener('change_identity', function(evt) {
	    if (window.identity && window.identity != rcmail.env.identity) {
	      rcmail.http_request('mail/plugin.refresh_store_target_selection', 
	          {
	            "_account": rcmail.env.identities_to_bal[rcmail.env.identity],
	          }
	      , rcmail.set_busy(true, 'loading'));
	    }
	    window.identity = rcmail.env.identity;
	  });
	  rcmail.addEventListener('responseafterplugin.refresh_store_target_selection', function(evt) {
	    $("#composeoptions .composeoption select[name=\"_store_target\"]").replaceWith(evt.response.select_html);
	    $('#_compose_hidden_account').val(rcmail.env.identities_to_bal[rcmail.env.identity]);
    });
}

/**
 * Show password change page as jquery UI dialog
 */
function show_password_change()
{
  if (rcmail.is_framed()) {
    var frame = $('<iframe>').attr('id', 'changepasswordframe')
    .attr('src', rcmail.url('settings/plugin.melanie2_moncompte') + '&_fid=rcmmodifmdp&_framed=1')
    .attr('frameborder', '0')
    .appendTo(parent.document.body);
  }
  else {
    var frame = $('<iframe>').attr('id', 'changepasswordframe')
    .attr('src', rcmail.url('settings/plugin.melanie2_moncompte') + '&_fid=rcmmodifmdp&_framed=1')
    .attr('frameborder', '0')
    .appendTo(document.body);
  }
  

  var h = Math.floor($(window).height() * 0.75);
  var buttons = {};

  frame.dialog({
    modal: true,
    resizable: false,
    closeOnEscape: true,
    title: rcmail.env.passwordchange_title,
    close: function() {
      frame.dialog('destroy').remove();
    },
    buttons: buttons,
    width: 700,
    height: 500,
    rcmail: rcmail
  }).width(680);
}

if (rcmail
		&& (rcmail.env.task == 'mail' || rcmail.env.task == 'addressbook')
		&& typeof(rcube_list_widget) !== 'undefined') {
	/**
	 * Réécriture de la fonction clear de la classe javascript rcube_list_widget
	 * Permet d'ajouter le triggerEvent clear
	 * Nécessaire pour le scroll infini notamment
	 */
	rcube_list_widget.prototype.clear = function(sel)
	{
		  if (this.tagname == 'table') {
		    var tbody = document.createElement('tbody');
		    this.list.insertBefore(tbody, this.tbody);
		    this.list.removeChild(this.list.tBodies[1]);
		    this.tbody = tbody;
		  }
		  else {
		    $(this.row_tagname() + ':not(.thead)', this.tbody).remove();
		  }

		  this.rows = {};
		  this.rowcount = 0;

		  if (sel)
		    this.clear_selection();

		  // reset scroll position (in Opera)
		  if (this.frame)
		    this.frame.scrollTop = 0;

		  // fix list header after removing any rows
		  this.resize();
		  // PAMELA - triggerEvent clear for list
		  this.triggerEvent('clear');
	};
}
