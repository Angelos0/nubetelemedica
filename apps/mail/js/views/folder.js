/* global Backbone, Mail, models, oc_defaults, _ */

var views = views || {};

views.Folder = Backbone.Marionette.ItemView.extend({

	template: '#mail-folder-template',

	events: {
		'click .collapse' : 'collapseFolder',
		'click .folder' : 'loadFolder'
	},

	initialize: function(options) {
		this.model = options.model;
	},

	collapseFolder: function(e) {
		e.preventDefault();
		this.model.toggleOpen();
	},

	loadFolder: function(e) {
		e.preventDefault();
		var accountId = this.model.get('accountId');
		var folderId = $(e.currentTarget).parent().data('folder_id');
		var noSelect = $(e.currentTarget).parent().data('no_select');
		Mail.UI.loadFolder(accountId, folderId, noSelect);
	},

	onRender: function() {
		// Get rid of that pesky wrapping-div.
		// Assumes 1 child element present in template.
		this.$el = this.$el.children();
		// Unwrap the element to prevent infinitely
		// nesting elements during re-render.
		this.$el.unwrap();
		this.setElement(this.$el);
	}
});

views.Account = Backbone.Marionette.CompositeView.extend({

	collection: null,
	model: null,

	template: '#mail-account-template',

	childView: views.Folder,

	childViewContainer: '#mail_folders',

	initialize: function(options) {
		this.model = options.model;
		this.collection = this.model.get('folders');
	}

});

views.Folders = Backbone.Marionette.CollectionView.extend({

	// The collection will be kept here
	collection: null,

	childView: views.Account,

	initialize: function() {
		this.collection = new models.AccountList();
	},

	getFolderById: function(accountId, folderId) {
		var activeAccount = accountId || Mail.State.currentAccountId;
		folderId = folderId || Mail.State.currentFolderId;
		activeAccount = this.collection.get(activeAccount);
		var activeFolder = activeAccount.get('folders').get(folderId);
		if (!_.isUndefined(activeFolder)) {
			return activeFolder;
		}

		// bad hack to navigate down the tree ...
		var delimiter = activeAccount.get('delimiter');
		folderId = atob(folderId);
		activeFolder = activeAccount;
		var parts = folderId.split(delimiter);
		var k = '';
		_.each(parts, function(p) {
			if (k.length > 0) {
				k += delimiter;
			}
			k += p;

			var folders = activeFolder.folders || activeFolder.get('folders');
			activeFolder = folders.filter(function(f) {
				return f.id === btoa(k);
			}).shift();
		});
		return activeFolder;
	},

	changeUnseen: function(model, unseen) {
		// TODO: currentFolderId and currentAccountId should be an attribute of this view
		var activeFolder = this.getFolderById();
		if (unseen) {
			activeFolder.set('unseen', activeFolder.get('unseen') + 1);
		} else {
			if (activeFolder.get('unseen') > 0) {
				activeFolder.set('unseen', activeFolder.get('unseen') - 1);
			}
		}
		this.updateTitle();
	},

	updateTitle: function() {
		var activeEmail = '';
		if (Mail.State.currentAccountId !== -1) {
			var activeAccount = Mail.State.currentAccountId;
			activeAccount = this.collection.get(activeAccount);
			activeEmail = ' - ' + activeAccount.get('email');
		}
		var activeFolder = this.getFolderById();
		var unread = activeFolder.unseen;
		var name = activeFolder.name || activeFolder.get('name');

		if (unread > 0) {
			window.document.title = name + ' (' + unread + ')' +
			activeEmail + ' - Mail - ' + oc_defaults.title;
		} else {
			window.document.title = name + activeEmail +
			' - Mail - ' + oc_defaults.title;
		}
	}

});
