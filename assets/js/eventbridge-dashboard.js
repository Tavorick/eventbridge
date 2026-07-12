( function () {
	'use strict';

	var config = window.EventBridgeDashboard;
	var colors = [ '#2271b1', '#d63638', '#00a32a' ];
	var names = [ 'Unieke interacties', 'Browser events', 'CAPI started' ];
	var keys = [ 'interactions', 'browser', 'capi_started' ];
	var resizeTimer;

	function validData( data ) {
		return data && Array.isArray( data.labels ) && keys.every( function ( key ) {
			return Array.isArray( data[ key ] ) && data[ key ].length === data.labels.length;
		} );
	}

	function prepareCanvas( canvas ) {
		var ratio = window.devicePixelRatio || 1;
		var width = Math.max( canvas.parentElement.clientWidth, 300 );
		var height = 320;
		canvas.width = width * ratio;
		canvas.height = height * ratio;
		canvas.style.width = width + 'px';
		canvas.style.height = height + 'px';
		var context = canvas.getContext( '2d' );
		context.setTransform( ratio, 0, 0, ratio, 0, 0 );
		context.font = '12px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
		return { context: context, width: width, height: height };
	}

	function maximum( data ) {
		var value = 0;
		keys.forEach( function ( key ) {
			data[ key ].forEach( function ( number ) { value = Math.max( value, Number( number ) || 0 ); } );
		} );
		return Math.max( 1, value );
	}

	function axes( chart, data, bottom, labelStep, groupedLabels ) {
		var context = chart.context;
		var left = 48;
		var top = chart.width < 500 ? 62 : 42;
		var right = chart.width - 18;
		var max = maximum( data );
		context.strokeStyle = '#dcdcde';
		context.fillStyle = '#646970';
		context.lineWidth = 1;
		context.textAlign = 'right';
		for ( var tick = 0; tick <= 4; tick++ ) {
			var y = top + ( bottom - top ) * tick / 4;
			context.beginPath(); context.moveTo( left, y ); context.lineTo( right, y ); context.stroke();
			context.fillText( Math.round( max * ( 4 - tick ) / 4 ), left - 8, y + 4 );
		}
		context.textAlign = 'center';
		data.labels.forEach( function ( label, index ) {
			if ( index % labelStep !== 0 ) { return; }
			var x = groupedLabels ? left + ( right - left ) * ( index + 0.5 ) / data.labels.length : ( data.labels.length === 1 ? ( left + right ) / 2 : left + ( right - left ) * index / ( data.labels.length - 1 ) );
			if ( groupedLabels ) {
				context.save(); context.translate( x, bottom + 10 ); context.rotate( -Math.PI / 5 ); context.textAlign = 'right'; context.fillText( String( label ).slice( 0, 14 ), 0, 0 ); context.restore();
			} else {
				context.fillText( String( label ).slice( 0, 18 ), x, bottom + 22 );
			}
		} );
		return { left: left, top: top, right: right, bottom: bottom, max: max };
	}

	function legend( chart ) {
		var context = chart.context;
		context.textAlign = 'left';
		names.forEach( function ( name, index ) {
			var narrow = chart.width < 500;
			var x = narrow ? 12 + ( index % 2 ) * Math.max( 145, chart.width / 2 ) : 50 + index * 145;
			var y = narrow ? 10 + Math.floor( index / 2 ) * 22 : 10;
			context.fillStyle = colors[ index ]; context.fillRect( x, y, 12, 12 );
			context.fillStyle = '#1d2327'; context.fillText( name, x + 18, y + 10 );
		} );
	}

	function lineChart( canvas, data ) {
		var chart = prepareCanvas( canvas );
		var plot = axes( chart, data, 278, 1, false );
		legend( chart );
		keys.forEach( function ( key, series ) {
			chart.context.strokeStyle = colors[ series ]; chart.context.fillStyle = colors[ series ]; chart.context.lineWidth = 2;
			chart.context.beginPath();
			data[ key ].forEach( function ( number, index ) {
				var x = data.labels.length === 1 ? ( plot.left + plot.right ) / 2 : plot.left + ( plot.right - plot.left ) * index / ( data.labels.length - 1 );
				var y = plot.bottom - ( Number( number ) || 0 ) / plot.max * ( plot.bottom - plot.top );
				if ( index ) { chart.context.lineTo( x, y ); } else { chart.context.moveTo( x, y ); }
			} );
			chart.context.stroke();
			data[ key ].forEach( function ( number, index ) {
				var x = data.labels.length === 1 ? ( plot.left + plot.right ) / 2 : plot.left + ( plot.right - plot.left ) * index / ( data.labels.length - 1 );
				var y = plot.bottom - ( Number( number ) || 0 ) / plot.max * ( plot.bottom - plot.top );
				chart.context.beginPath(); chart.context.arc( x, y, 3, 0, Math.PI * 2 ); chart.context.fill();
			} );
		} );
	}

	function barChart( canvas, data ) {
		var chart = prepareCanvas( canvas );
		var plot = axes( chart, data, 240, 1, true );
		var groupWidth = ( plot.right - plot.left ) / Math.max( data.labels.length, 1 );
		var barWidth = Math.min( 18, groupWidth / 4 );
		legend( chart );
		data.labels.forEach( function ( label, group ) {
			keys.forEach( function ( key, series ) {
				var value = Number( data[ key ][ group ] ) || 0;
				var height = value / plot.max * ( plot.bottom - plot.top );
				var center = plot.left + groupWidth * ( group + 0.5 );
				chart.context.fillStyle = colors[ series ];
				chart.context.fillRect( center + ( series - 1 ) * barWidth - barWidth / 2, plot.bottom - height, barWidth - 2, height );
			} );
		} );
	}

	function draw() {
		if ( ! config ) { return; }
		var daily = document.getElementById( 'eventbridge-daily-chart' );
		var events = document.getElementById( 'eventbridge-events-chart' );
		if ( daily && validData( config.daily ) ) { lineChart( daily, config.daily ); }
		if ( events && validData( config.events ) ) { barChart( events, config.events ); }
	}

	window.addEventListener( 'resize', function () {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( draw, 150 );
	} );
	draw();
}() );
