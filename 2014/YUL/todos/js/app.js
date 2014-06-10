// Some Underscore settings
_.templateSettings = {
	evaluate:    /\{\{(.+?)\}\}/g,
	interpolate: /\{\{=(.+?)\}\}/g,
	escape:      /\{\{-(.+?)\}\}/g
};

var ToDo = ToDo || {};

ToDo.Models = ToDo.Models || {};

ToDo.Models.ToDo = ( function( $, Backbone ) {
	return Backbone.Model.extend( {
		defaults: function() {
			return {
				id: 0,
				title: '',
				checked: '',
				completedBy: false,
				latestChange: 0,
				source: 'local'
			}
		},

		initialize: function() {
			// Update the server version when the Model changes
			this.listenTo( this, 'change', this.update );
		},

		// Send the AJAX to WP
		update: function() {
			if ( this.get( 'source' ) == 'local' ) {
				// wp_ajax_(action) format
				var data = {
					action: 'todos_check',
					id: this.id,
					checked: this.get( 'checked' )
				};
				var jqXHR = $.ajax( {
					dataType: 'json',
					url: ToDo.ajaxurl,
					xhrFields: {
						withCredentials: true
					},
					data: data
				} )
				.done( function( response, textStatus, jqXHR ) {
					ToDo.toDos.add( response.data[0], { merge: true, silent: true } );
				} )
				.always( function() {
				} );
			}
		}
	} );
} )( jQuery, Backbone );

ToDo.Models.Widget = ( function( $, Backbone ) {
	return Backbone.Model.extend( {} );
} )( jQuery, Backbone );

ToDo.Collections = ToDo.Collections || {};

ToDo.Collections.ToDos = ( function( $, Backbone ) {
	return Backbone.Collection.extend( {
		model: ToDo.Models.ToDo
	} );
} )( jQuery, Backbone );

ToDo.Views = ToDo.Views || {};

ToDo.Views.ToDo = ( function( $, Backbone ) {
	return Backbone.View.extend( {
		model: ToDo.Models.ToDo,

		tagName: 'li',

		template: _.template( $( '#todos-template' ).html() ),

		events: {
			// Click on checkbox
			'click .on-completion': 'onCompletion'
		},

		initialize: function() {
			// Update the View when the model changes
			this.listenTo( this.model, 'change', this.render );

			// Start by rendering the View
			this.render();
		},

		render: function() {
			this.$el.html( this.template( this.model.toJSON() ) );
			return this;
		},

		// Update the Model when an event takes place on the View
		onCompletion: function( e ) {
			if ( e.currentTarget.checked ) {
				this.model.set( {
					checked: 'checked',
					completedBy: ToDo.currentUser,
					source: 'local'
				} );
			} else {
				this.model.set( {
					checked: '',
					completedBy: false,
					source: 'local'
				} );
			}
		}
	} );
} )( jQuery, Backbone );

ToDo.Views.Widget = ( function( $, Backbone ) {
	return Backbone.View.extend( {
		model: ToDo.Models.Widget,

		collection: ToDo.Collections.ToDos,

		tagName: 'ul',

		subviews: [],

		initialize: function() {
			this.listenTo( this.collection, 'add', this.addOne );

			// Each widget only needs to be rendered on initial load
			this.addAll();
			this.render();
		},

		render: function() {
			$( '#' + this.model.get( 'widgetID' ) ).append( this.el );
			return this;
		},

		addOne: function( todo ) {
			var view = new ToDo.Views.ToDo( {
				model: todo
			} );
			this.subviews[ todo.get( 'widgetID' ) ] = view;
			this.$el.prepend( view.el );
			return this;
		},

		addAll: function() {
			this.collection.each( function( todo ) {
				this.addOne( todo );
			}, this );
			return this;
		}
	} );
} )( jQuery, Backbone );

ToDo.since = Date.now();
ToDo.Polling = ( function( $, Backbone ) {
	return {
		pollInterval: 6000,

		poll: function() {
			// wp_ajax_(action) format
			var data = {
				action: 'todos_poll',
				since: ToDo.since
			};

			ToDo.current = Date.now();

			var jqXHR = $.ajax( {
				dataType: 'json',
				url: ToDo.ajaxurl,
				data: data
			} )
			.done( function( response, textStatus, jqXHR ) {
				// Move since forward if we have a result
				if ( 'undefined' != typeof response.data ) {
					ToDo.since = ToDo.current;

					// Process the returned poll data
					for ( m = 0, dl = response.data.length; m < dl; m++ ) {
						var todo = response.data[m];
						todo.source = 'poll';
						ToDo.toDos.add( todo, { merge: true } );
					}
				}
			} )
			.always( function() {
				// Set the next poll
				ToDo.Polling.poller = setTimeout( ToDo.Polling.poll, ToDo.Polling.pollInterval ); 
			} );
		}
	}
} )( jQuery, Backbone );

jQuery( document ).ready( function( $ ) {
	// Find the priming data
	var primer = JSON.parse( $( '#todos-data' ).text() );
	ToDo.toDos = new ToDo.Collections.ToDos( primer );

	// Set currentUser
	ToDo.currentUser = JSON.parse( $( '#todos-current-user' ).text() );

	// Set up a model and view for each widget
	ToDo.WidgetModels = [];
	ToDo.WidgetViews = [];
	_.each( $( '.widget.todos' ), function( widget ) {
		var data = $( widget ).find( 'div' );
		var model = new ToDo.Models.Widget( {
			widgetID: widget.id,
			count: data.data( 'count' )
		} );
		var view = new ToDo.Views.Widget( {
			model: model,
			collection: ToDo.toDos
		} );
		ToDo.WidgetModels.push( model );
		ToDo.WidgetViews.push( view );
	} );

	// Start polling
	ToDo.Polling.poll();
} );
