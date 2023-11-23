<?php
$base=isset($_REQUEST['base']) ? $_REQUEST['base'] : "exercise";
$home=isset($_REQUEST['home']) ? $_REQUEST['home'] : "index.html";
$nlesson=isset($_REQUEST['nlsn']) ? (int)$_REQUEST['nlsn'] : 1;
$clesson=isset($_REQUEST['curr']) ? (int)$_REQUEST['curr'] : 1;
?>
<html>
<head>
<meta charset="utf-8">
<title>IMPROS - Improve Your Prosody Vs 1.0</title>

<link rel="stylesheet" type="text/css" href="impros.css">

		<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

<script type="text/javascript" src="AudioContextMonkeyPatch.js"></script>
<script type="text/javascript" src="filter.min.js"></script>
<script type="text/javascript" src="mfcc.min.js"></script>
<script type="text/javascript" src="loess.min.js"></script>
<script>
//
// (c) 2015 Mark Huckvale University College London
//
// manifest constants
var SRATE=48000;	// sampling rate (fixed by Audio object)
var FXRATE=200;   // fundamental frequency rate
var MAXRECORD=10;	// maximum recording = 10s

// lesson
var home="<?php echo $home; ?>";
var base="<?php echo $base; ?>";
var nlesson=<?php echo $nlesson; ?>;
var clesson=<?php echo $clesson; ?>;
var lesson="<?php echo "$base$clesson"; ?>";

// audio
var context=null;
var micsource=null;
var capturenode=null;
var recording=0;
var	sendsrc;

// audio file
var filename="Waveform";
var fsignal=new Float32Array(SRATE);
var filedata=null;
var fileblob=null;
var fileurl=null;
var ffxlo=50,ffxhi=500;
var	ftab=[];
var fdata=[];

// audio captured
var csigcap=[];
var csignal=new Float32Array(SRATE);
var cfx=[];
var cvs=[];
var cfxlen=0;
var cfxlo=50,cfxhi=500;
var	ctab=[];
var	cdata=[];

// utility function
function $(obj) { return document.getElementById(obj); }

// send a message to the console
function trace(message){
	console.log(message);
}

// get screen size and calculate co-ordinates of screen centre
function screensize()
{
	var e = window, a = 'inner';
	if ( !( 'innerWidth' in window ) ) {
		a = 'client';
		e = document.documentElement || document.body;
	}
	return { width : e[a+'Width'], height : e[a+'Height'], cx : e[ a+'Width' ]/2 , cy : e[ a+'Height' ]/2 }
}

// assign pitch values to track
function fxassign(cfx,cvs,cfxlen)
{
	var	i,j,k,slope;
	var	x=new Array(cfx.length);

//	trace("cfx before="+JSON.stringify(cfx));

	for (i=0;i<cfx.length;i++) x[i] = i/FXRATE;

	cfx=smoothfxloess(x,cfx,cvs);

//	if (cvs[0]<=0.3) {
//		for (j=0;j<cfx.length;j++) {
//			if (cvs[j]>0.3) {
//				for (k=0;k<j;k++) cfx[k]=cfx[j];
//				break;
//			}
//		}
//	}
//	if (cvs[cfx.length-1]<=0.3) {
//		for (j=cfx.length-2;j>=0;j--) {
//			if (cvs[j]>0.3) {
//				for (k=j+1;k<cfx.length;k++) cfx[k]=cfx[j];
//				break;
//			}
//		}
//	}
//	for (i=0;i<cfx.length-1;i++) {
//		if (cvs[i+1]<=0.3) {
//			for (j=i;j<cfx.length;j++) {
//				if (cvs[j]>0.3) {
//					slope=(cfx[j]-cfx[i])/(j-i);
//					for (k=i+1;k<j;k++) cfx[k]=cfx[i]+(k-i)*slope;
//					break;
//				}
//			}
//		}
//	}

//	trace("cfx after="+JSON.stringify(cfx));


	for (i=0;i<ctab.length;i++) ctab[i].fx=[];

	for (i=0;i<cfx.length;i++) {
		var t=i/FXRATE;
		for (j=0;j<ctab.length;j++) {
			if ((ctab[j].start <= t) &&(t < ctab[j].stop)) {
				ctab[j].fx.push(cfx[i]);
			}
		}
	}
}

var pitchworker=null;

// calculate pitch
function dopitch()
{
	// downsample by 3 before analysis
	var nfsamp=Math.floor(csignal.length/3);
	var ffsignal=new Float32Array(nfsamp);
	var filt=new Filter();
	filt.design(filt.LOW_PASS,4,SRATE/3,SRATE/3,SRATE);
	for (var i=0;i<nfsamp;i++) {
		filt.sample(csignal[3*i]);
		filt.sample(csignal[3*i+1]);
		ffsignal[i]=filt.sample(csignal[3*i+2]);
	}

	// create worker thread for calculation
	pitchworker= new Worker("fxswipe.min.js");
	pitchworker.onmessage=function(event) {
		console.timeEnd("FXSwipe");
		cfx=event.data.fx;
	 	cvs=event.data.vs;
		cfxlen=event.data.fxlen;
		// assign pitch values to table
		fxassign(cfx,cvs,cfxlen);
		// draw pitch track
		dopanels();
	}

	// generate pitch in worker thread
	console.time("FXSwipe");
	pitchworker.postMessage({ signal : ffsignal, srate: SRATE/3 });

}

// create audio context
function createcontext()
{
	// create context - once only
	if (context==null) {
		try {
			context = new window.AudioContext();
//			trace("context.sampleRate="+context.sampleRate);
			SRATE=context.sampleRate;
			//context.createGainNode();		// this may be a fix for iPad
		}
		catch(e) {
			alert('Web Audio API is not supported in this browser. Try Chrome, Firefox or Safari.');
		}
	}
}

// euclidean distance
function eudist(v1,v2)
{
	var sum=0;
	for (var i=0;i<v1.length;i++) sum += (v1[i]-v2[i])*(v1[i]-v2[i]);
	return Math.sqrt(sum/v1.length);
}

function minof3(a,b,c)
{
	if (a < b) {
		if (a < c)
			return(a);
		else
			return(c);
	}
	else {
		if (b < c)
			return(b);
		else
			return(c);
	}
}
function minof3idx(a,b,c)
{
	if (a < b) {
		if (a < c)
			return(1);
		else
			return(3);
	}
	else {
		if (b < c)
			return(2);
		else
			return(3);
	}
}

// perform alignment to generate capture word table
function doalign(fdata,cdata,ftab)
{
	var	i,j;
	var	up,left,diag;
	var cudist=new Array(fdata.length);
	var bptr=new Array(fdata.length);

	for (i=0;i<fdata.length;i++) {
		cudist[i]=new Array(cdata.length);
		bptr[i]=new Array(cdata.length);
	}

	/* dynamic programming */
	for (i=0;i<fdata.length;i++) {
		for (j=0;j<cdata.length;j++) {
			if ((i==0)&&(j==0)) {
				up=1e10;
				left=1e10;
				diag=0;
			}
			else if (i==0) {
				up=1e10;
				left=cudist[i][j-1];
				diag=1e10;
			}
			else if (j==0) {
				up=cudist[i-1][j];
				left=1e10;
				diag=1e10;
			}
			else {
				up=cudist[i-1][j];
				left=cudist[i][j-1];
				diag=cudist[i-1][j-1];
			}
			cudist[i][j]=eudist(fdata[i].coef,cdata[j].coef)+minof3(up,left,diag);
			bptr[i][j]=minof3idx(up,left,diag);
		}
	}

	/* get best alignment */
	var align=new Array(fdata.length);
	i=fdata.length-1;
	j=cdata.length-1;
	while (i >= 0) {
		align[i]={ftime:fdata[i].time,ctime:cdata[j].time};
		if (bptr[i][j]==1) {		// up
			i--;
		}
		else if (bptr[i][j]==2) {	// left
			j--;
		}
		else {						// diag
			i--;
			j--;
		}
	}
//	trace("align="+JSON.stringify(align));

	/* copy over words and set alignment */
	ctab = new Array(ftab.length);
	for (i=0;i<ftab.length;i++) {
		ctab[i]={};
		ctab[i].wrd = ftab[i].wrd;
		var dist=1e10;
		for (j=0;j<align.length;j++) {
			if (Math.abs(ftab[i].start-align[j].ftime)<dist) {
				ctab[i].start=align[j].ctime;
				dist=Math.abs(ftab[i].start-align[j].ftime)
			}
		}
		dist=1e10;
		for (j=0;j<align.length;j++) {
			if (Math.abs(ftab[i].stop-align[j].ftime)<dist) {
				ctab[i].stop=align[j].ctime;
				dist=Math.abs(ftab[i].stop-align[j].ftime)
			}
		}
		ctab[i].fx=[];
//		trace("wrd="+ctab[i].wrd+" fstart="+ftab[i].start+" fstop="+ftab[i].stop+" cstart="+ctab[i].start+" cstop="+ctab[i].stop);
	}

}

// stop recording
function doStop()
{
	if (recording) {
		$('recmask').style.visibility='hidden';
		$('recorddlg').style.visibility='hidden';
		recording=0;
//		trace("Got "+csigcap.length+" samples");
		csignal=new Float32Array(csigcap.length);
		for (var i=0;i<csigcap.length;i++) csignal[i]=csigcap[i]/csigmax;
		// calculate MFCC
		console.time("MFCC");
		var mfcc=new MFCC(SRATE);
		cdata=mfcc.calcMFCC(csignal);
		console.timeEnd("MFCC");
//		trace("MFCC:");
//		for (var i=0;i<fdata.length;i++) trace(JSON.stringify(cdata[i]));
		doalign(fdata,cdata,ftab);
		dopanels();
		dopitch()
	}
	else
		sendsrc.stop();
}

function onrecorddlg()
{
	doStop();
}

// draw VU meter
var lastvu=-50;

function setvumeter(peak)
{
	var canvas=document.getElementById("vumeter");
	var ctx=canvas.getContext('2d');
	ctx.font="bold 20px Arial";
	ctx.clearRect(0,0,canvas.width,canvas.height);
	ctx.fillStyle="rgb(128,128,128)";
	ctx.textAlign="center";
	var vu=20*Math.log10(peak);
	if (vu < lastvu)
		lastvu = 0.75*lastvu+0.25*vu;
	else
		lastvu = vu;
	var width=Math.floor(190+3*lastvu);
	if (width < 0) width=0;
//	ctx.fillText(vu, 100, 40);
	ctx.fillStyle = "rgb(200, 0, 0)";
    ctx.fillRect (5, 5, width, 40);
}

// start audio processing
var capturestream;
function startrecording(stream)
{
   capturestream=stream;
	micsource = context.createMediaStreamSource(stream);
	capturenode = context.createScriptProcessor(8192, 1, 1);
	capturenode.onaudioprocess = function(e) {
		if (recording) {
			var buf=e.inputBuffer.getChannelData(0);
			var peak=0;
			for (i=0;i<buf.length;i++) {
				if (buf[i] > csigmax) csigmax = buf[i];
				if (buf[i] < -csigmax) csigmax = -buf[i];
				if (buf[i] > peak) peak = buf[i];
				if (buf[i] < -peak) peak = -buf[i];
				csigcap.push(buf[i]);
			}
			if (csigcap.length/SRATE > MAXRECORD) doStop();
			setvumeter(peak);
		}
	};

	// connect microphone to processing node
	micsource.connect(capturenode);
	capturenode.connect(context.destination);

}

// start/pause recording
function doRecord()
{

	if (!recording) {
		csigmax=0;
		csigcap = new Array();
	}

	if (micsource==null) {
		navigator.getMedia = ( navigator.getUserMedia ||
                         navigator.webkitGetUserMedia ||
                         navigator.mozGetUserMedia ||
                         navigator.msGetUserMedia);

		navigator.getMedia({audio:{ optional: [ {googNoiseSuppression: false},{googNoiseSuppression2: false} ]}}, startrecording, function() { alert('getUserMedia() failed - use https: address'); });
	}

	// start/pause function
	if (recording) {
		$('recmask').style.visibility='hidden';
		$('recorddlg').style.visibility='hidden';
		recording=0;
//		trace("Got "+csigcap.length+" samples");
		csignal=new Float32Array(csigcap.length);
		for (var i=0;i<csigcap.length;i++) csignal[i]=csigcap[i]/csigmax;
		// calculate MFCC
		console.time("MFCC");
		var mfcc=new MFCC(SRATE);
		cdata=mfcc.calcMFCC(csignal);
		console.timeEnd("MFCC");
//		trace("MFCC:");
//		for (var i=0;i<fdata.length;i++) trace(JSON.stringify(cdata[i]));
		doalign(fdata,cdata,ftab);
		dopanels();
		dopitch();
      closedown();
	}
	else {
		$('recmask').style.visibility='visible';
		var dlg=$('recorddlg');
		var rpos = $('recmask').getBoundingClientRect();
		var voffset=(rpos.height - dlg.offsetHeight)/2;
		dlg.style.top = (rpos.top + voffset) + "px";
		dlg.style.left = (screensize().cx - dlg.offsetWidth/2) + "px";
		dlg.style.visibility='visible';
		recording=1;
	}

}

// play some audio
function playaudio(sig,stime,etime)
{
	var ssamp=Math.floor(stime*SRATE);
	var esamp=Math.floor(etime*SRATE);
	var nsamp = esamp-ssamp;
	if (nsamp <= 0) return;

	// create audio buffer source node
	sendsrc = context.createBufferSource();
	sendbuf = context.createBuffer(1,nsamp,SRATE);

	// copy in the signal
	senddat = sendbuf.getChannelData(0);
	for (i=0;i<nsamp;i++) senddat[i] = sig[ssamp+i];

	// kick it off
	sendsrc.buffer = sendbuf;
	sendsrc.loop = false;
	sendsrc.connect(context.destination);
	sendsrc.start(context.currentTime + 0.001);		// this may be a fix for iPad
}

// detect mouse click on fpanel
function fpanelclick(evt)
{
	evt = evt || window.event;
	var rect = $('fpanel').getBoundingClientRect();
	var x= evt.clientX - rect.left;
	for (var i=0;i<ftab.length;i++) {
		if ((ftab[i].xl <= x) && (x <= ftab[i].xr)) {
			playaudio(fsignal,ftab[i].start,ftab[i].stop);
			return;
		}
	}
}

// detect mouse click on cpanel
function cpanelclick(evt)
{
	evt = evt || window.event;
	var rect = $('cpanel').getBoundingClientRect();
	var x= evt.clientX - rect.left;
	for (var i=0;i<ftab.length;i++) {
		if ((ctab[i].xl <= x) && (x <= ctab[i].xr)) {
			playaudio(csignal,ctab[i].start,ctab[i].stop);
			return;
		}
	}
}

// play master recording
function doListen()
{
	playaudio(fsignal,0,fsignal.length/SRATE);
}

// play student recording
function doPlay()
{
	playaudio(csignal,ctab[0].start,ctab[ctab.length-1].stop);
}

function oncontextmenu(evt)
{
	evt = evt || window.event;
	evt.preventDefault();
	evt.stopPropagation();
	return false;
}

// dialog cancel button handler
function cancel(dlg)
{
	$('mask').style.visibility='hidden';
	$(dlg).style.visibility='hidden';
}

// set bytes in a buffer
function writeUTFBytes(view, offset, string)
{
	var lng = string.length;
	for (var i = 0; i < lng; i++) {
		view.setUint8(offset + i, string.charCodeAt(i));
	}
}

// make a WAV file from signal
function makeWAV(signal)
{
  var buffer = new ArrayBuffer(44 + signal.length * 2);
	var view = new DataView(buffer);

  // RIFF chunk descriptor
	writeUTFBytes(view, 0, 'RIFF');
	view.setUint32(4, 44 + signal.length * 2, true);
	writeUTFBytes(view, 8, 'WAVE');
	// FMT sub-chunk
	writeUTFBytes(view, 12, 'fmt ');
	view.setUint32(16, 16, true);
	view.setUint16(20, 1, true);
	view.setUint16(22, 1, true);
	view.setUint32(24, SRATE, true);
	view.setUint32(28, SRATE * 2, true);
  view.setUint16(32, 2, true);
  view.setUint16(34, 16, true);
	// data sub-chunk
	writeUTFBytes(view, 36, 'data');
	view.setUint32(40, signal.length * 2, true);

	// write the PCM samples
	var lng = signal.length;
	var index = 44;
	for (var i = 0; i < lng; i++) {
		view.setInt16(index, signal[i] * 30000, true);
		index += 2;
	}

	// our final binary blob
	var blob = new Blob ( [ view ], { type : 'audio/wav' } );
	return blob;
}

// save file
function doSave()
{
	var a = document.createElement('a');
	a.href = window.URL.createObjectURL(makeWAV(csignal));
	a.download = 'download.wav';
	var event = document.createEvent("MouseEvents");
  event.initMouseEvent(
       "click", true, false, window, 0, 0, 0, 0, 0
       , false, false, false, false, 0, null
  );
  a.dispatchEvent(event);
//	a.click();
}

// open a file
function onopenfiledlg()
{
	$('mask').style.visibility='hidden';
	$('openfiledlg').style.visibility='hidden';
	var file = $('filechoice').files[0];
	filename = file.name;

	createcontext();

	var reader = new FileReader();
	reader.onload = function(e) {
//		trace("reader.readyState="+reader.readyState);
		filedata = e.target.result;
//		trace("filedata.length="+filedata.byteLength);
		fileblob = new Blob([filedata], {type: 'audio/wav'});
		fileurl = URL.createObjectURL(fileblob);
	    context.decodeAudioData(filedata,
			function onSuccess(buffer) {
				var siglen = buffer.length;
  				if (siglen/SRATE > MAXRECORD) siglen=Math.floor(MAXRECORD*SRATE);
				var srcbuf = buffer.getChannelData(0);
				csigmax=0;
				for (i=0;i<siglen;i++) {
					var s = srcbuf[i];
					if (s > csigmax) csigmax=s;
		            if (s < -csigmax) csigmax = -s;
  				}
				csignal = new Float32Array(siglen);
				for (i=0;i<siglen;i++) {
					csignal[i] = srcbuf[i]/csigmax;
				}
				// calculate MFCC
				console.time("MFCC");
				var mfcc=new MFCC(SRATE);
				cdata=mfcc.calcMFCC(csignal);
				console.timeEnd("MFCC");
				doalign(fdata,cdata,ftab);
				dopanels();
				dopitch();
    		},
    		function onFailure() {
    			trace("decodeAudioData failed");
    		}
    	);
	};
	reader.readAsArrayBuffer(file);
}

// load file
function doLoad()
{
	$('mask').style.visibility='visible';
	var dlg=$('openfiledlg');
	dlg.style.top = screensize().cy - dlg.offsetHeight/2;
	dlg.style.left = screensize().cx - dlg.offsetWidth/2;
	dlg.style.visibility='visible';
}

// about
function doAbout()
{
	$('mask').style.visibility='visible';
	var dlg=$('aboutdlg');
	dlg.style.top = screensize().cy - dlg.offsetHeight/2;
	dlg.style.left = screensize().cx - dlg.offsetWidth/2;
	dlg.style.visibility='visible';
}

// display one panel
function displaypanel(target,tab,colour)
{
	var canvas=document.getElementById(target);
	var ctx=canvas.getContext('2d');

	ctx.clearRect(0,0,canvas.width,canvas.height);

	var	nbox=tab.length;
	var width=canvas.width-(nbox+1)*10;
	if ((nbox==0)||(width < 0)) return;

	var tscale=width/(tab[nbox-1].stop-tab[0].start);
	var height=canvas.height;
//	trace("nbox="+nbox+" width="+width+" height="+height+" scale="+tscale);

	ctx.font="bold "+Math.floor(height/8)+"px Arial";

	// find fx min & max
	var fxmin=500;
	var fxmax=50;
	if (tab[0].fx!='undefined') {
		for (var i=0;i<ftab.length;i++) {
			for (var j=0;j<tab[i].fx.length;j++) {
				var f=tab[i].fx[j];
				if (f < fxmin) fxmin=f;
				if (f > fxmax) fxmax=f;
			}
		}
	}
	if (fxmin < 50) fxmin=50;
	if (fxmax > 500) fxmax=500;
//	trace("fxmin="+fxmin+" fxmax="+fxmax);

	var ymin=height/8+20;
	var ymax=height-20;

	for (var i=0;i<nbox;i++) {
		tab[i].xl=10+10*i+Math.floor(tscale*(tab[i].start-tab[0].start));
		tab[i].xr=10+10*i+Math.floor(tscale*(tab[i].stop-tab[0].start));
		ctx.fillStyle="rgb(128,128,128)";
		ctx.fillRect(tab[i].xl,10,tab[i].xr-tab[i].xl,height-20);
		ctx.fillStyle = colour;
		ctx.textAlign="center";
		ctx.fillText(tab[i].wrd, (tab[i].xr+tab[i].xl)/2, 10+height/8);
		if (tab[i].fx!='undefined') {
			ctx.strokeStyle=colour;
			ctx.lineWidth=3;
			ctx.beginPath();
			ctx.moveTo(tab[i].xl,ymin+(ymax-ymin)*(fxmax-tab[i].fx[0])/(fxmax-fxmin));
			for (var j=1;j<tab[i].fx.length;j++) {
				ctx.lineTo(tab[i].xl+j*(tab[i].xr-tab[i].xl)/(tab[i].fx.length-1),ymin+(ymax-ymin)*(fxmax-tab[i].fx[j])/(fxmax-fxmin));
			}
			ctx.stroke();
		}
	}

}

// display the panels
function dopanels()
{
	displaypanel('fpanel',ftab,'#EEEE00');
	displaypanel('cpanel',ctab,'#00FF00');
}

// load the text description
function gettext(aname)
{
    // Note: this loads asynchronously
    var request = new XMLHttpRequest();
    request.open("GET", aname, true);

    // Our asynchronous callback
    request.onload = function() {
//    	trace("getext: got response onload()");
    	if (request.readyState==4) {
	    	var ptext = request.responseText;
	    	var lines = ptext.trim().split("\n");
	    	if (lines[0].charAt(0)=='<') {
	    		$('content').innerHTML=lines[0];
	    		lines=lines.slice(1,lines.length);
	    	}
	    	ftab = new Array(Math.floor(lines.length/2));
	    	for (i=0;i<ftab.length;i++) {
	    		var fld=lines[2*i].trim().split(",");
	    		ftab[i]={};
	    		ftab[i].start=fld[0];
	    		ftab[i].stop=fld[1];
	    		ftab[i].wrd=fld[2];
	    		if (ftab[i].wrd=="") ftab[i].wrd=",";
	    		ftab[i].fx=lines[2*i+1].trim().split(",");
	    		for (var j=0;j<ftab[i].fx.length;j++) ftab[i].fx[j]=parseFloat(ftab[i].fx[j]);
	    	}
//			trace("Loaded "+ftab.length+" annotations");
//			trace("ftab="+JSON.stringify(ftab));
	    	dopanels();
    	};
    };

//	trace("Request "+aname);
    request.send();

}

// load the audio in the background
function getaudio(aname,tname)
{
    // Note: this loads asynchronously
    var request = new XMLHttpRequest();
    request.open("GET", aname, true);
    request.responseType = "arraybuffer";

    // Our asynchronous callback
    request.onload = function() {
//    	trace("getaudio: got response onload()");
    	context.decodeAudioData(request.response,
    		function onSuccess(buffer) {
				var siglen = buffer.length;
				fsignal = new Float32Array(siglen);
				var srcbuf = buffer.getChannelData(0);
				sigmax=0;
				for (i=0;i<siglen;i++) {
					var s = srcbuf[i];
					if (s > sigmax) sigmax=s;
					if (s < -sigmax) sigmax = -s;
				}
				for (i=0;i<siglen;i++) {
					fsignal[i] = srcbuf[i]/sigmax;
				}
//				trace("Loaded "+siglen+" audio samples");
				// calculate MFCC
				console.time("MFCC");
				var mfcc=new MFCC(SRATE);
				fdata=mfcc.calcMFCC(fsignal);
				console.timeEnd("MFCC");
//				trace("MFCC:");
//				for (var i=0;i<fdata.length;i++) trace(JSON.stringify(fdata[i]));
		        gettext(tname);
    		},
    		function onFailure() {
    			trace("decodeAudioData failed");
    		}
    	);
    };

//	trace("Request "+aname);
    request.send();

}

// load the audio file and text description
function loadaudiotext(aname,tname)
{
	createcontext();
	getaudio(aname,tname);
}

// clear down and load data
function doloaddata()
{
	fsignal=[];
	csignal=[];
	ctab=[];
	// load the master audio and txet
	loadaudiotext(lesson+'.wav',lesson+'.txt');
}

// do contents page
function doContents()
{
	window.location.href="<?php echo $home; ?>";
}

// do previous lesson
function doPrevious()
{
	clesson--;
	if (clesson < 1) clesson=1;
	lesson=base+clesson;
	doloaddata();
}

// do next lesson
function doNext()
{
	clesson++;
	if (clesson>nlesson) clesson=nlesson;
	lesson=base+clesson;
	doloaddata();
}


// set sizes of graphs to fit screen
function doresize()
{
	// main div
	var mwidth=screensize().width-2;
	var mheight=screensize().height-95;
	$("main").style.width=mwidth+"px";
	$("main").style.height=mheight+"px";

	var wwidth=mwidth-15;
	var wheight=Math.floor((mheight-35)/5);
	$("content").style.width=(wwidth-10)+"px";
	$("content").style.height=wheight+"px";
	$("fpanel").style.width=wwidth+"px";
	$("fpanel").style.height=(2*wheight)+"px";
	$("cpanel").style.width=wwidth+"px";
	$("cpanel").style.height=(2*wheight)+"px";
	$("fpanel").width=wwidth;
	$("fpanel").height=(2*wheight);
	$("cpanel").width=wwidth;
	$("cpanel").height=(2*wheight);
	$("vumeter").width=200;
	$("vumeter").height=50;
	var cpos=$('cpanel').getBoundingClientRect();
	$("recmask").style.top=cpos.top;
	$("recmask").style.left=cpos.left;
	$("recmask").style.width=$("cpanel").style.width;
	$("recmask").style.height=$("cpanel").style.height;
	dopanels();
}

// initialise whole page
function initialise() {
  // resize and draw graphs
  doresize();
  doloaddata();
}

// closedown
function closedown()
{
	if (micsource!=null) micsource.disconnect(capturenode);
	micsource=null;
	if (capturenode!=null) capturenode.disconnect(context.destination);
	capturenode=null;
	if (capturestream!=null) {
		var tracks=capturestream.getAudioTracks();
		console.log("Found "+tracks.length+" audio tracks to close");
		for (var i=0;i<tracks.length;i++) {
			tracks[i].stop();
			capturestream.removeTrack(tracks[i]);
		}
		capturestream=null;
	}
}

window.onblur=function() {
		recording=0;
      closedown();
}

</script>
    <style>
    body { -webkit-user-select: none; }
    </style>

</head>
<body onload="initialise()" onresize="doresize()" oncontextmenu="return false;">

<div id="menubar">
<div class="menubutton" onclick="doAbout()"><img src="improslogo.png"></div>
<div class="menulabel">LESSON</div>
<div class="menubutton" onclick="doContents()";>Contents</div>
<div class='menubutton' onclick='doPrevious()'>Prev</div>
<div class='menubutton' onclick='doListen()'>Play</div>
<div class='menubutton' onclick='doNext()'>Next</div>
<div class="menulabel">STUDENT</div>
<div class="menubutton" onclick="doLoad()";>Load</div>
<div class="menubutton" onclick="doSave()";>Save</div>
<div class="menubutton" onclick="doRecord()";>Record</div>
<div class="menubutton" onclick="doPlay()";>Play</div>
</div>

<div id="main">

<div class="textpanel" id="content">
</div>
<canvas class="panel" id="fpanel" onclick="fpanelclick(event)"></canvas>
<canvas class="panel" id="cpanel" onclick="cpanelclick(event)"></canvas>

</div>

<div id="footer">
	<div align='right' id='fcredits'>
	&copy; 2015 Mark Huckvale University College London
	</div>
</div>

<!-- Screen overlay mask -->
<div id="mask" class="background" style="visibility:hidden"></div>

<!-- Screen overlay mask for recording -->
<div id="recmask" class="mask" style="visibility:hidden"></div>

<!-- Load File Dialog -->
<div id="openfiledlg" class="modal" style="visibility:hidden">
<table border="0" cellpadding="3" cellspacing="3" width="100%">
<tr><td><h3>Select Audio File</h3></td></tr>
<tr><td height="60px"><input class="inputfile" type="file" id="filechoice"></td></tr>
<tr><td><button class="button" onclick="onopenfiledlg()">OK</button><button class="button" onclick="cancel('openfiledlg')">Cancel</button></td></tr>
</table>
</div>

<!-- Record Dialog -->
<div id="recorddlg" class="modal" style="visibility:hidden">
<table border="0" cellpadding="3" cellspacing="3" width="100%">
<tr><td><h3>Recording</h3></td></tr>
<tr><td><i>Click above to enable recording</i></td></tr>
<tr><td height="60px"><canvas class="vumeter" id="vumeter"></canvas></td></tr>
<tr><td><button class="button" onclick="onrecorddlg()">OK</button></td></tr>
</table>
</div>

<!-- About Dialog -->
<div id="aboutdlg" class="modal" style="visibility:hidden">
<table border="0" cellpadding="3" cellspacing="3" width="100%">
<tr><td><h3>IMPROS</h3>
<p>Improve Your Prosody
<p>Version 1.0
<p>&copy; 2015 Mark Huckvale
<p>University College London
<p>January 2015
</td></tr>
<tr><td><button class="button" onclick="cancel('aboutdlg')">OK</button></td></tr>
</table>
</div>

</body>


</html>