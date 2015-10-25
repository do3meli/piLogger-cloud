//----------------------------------------------------------
// generateGraph(graphname)
//----------------------------------------------------------
function generateGraph(graphname){
   
   // do ajax request and call draw_chart function if successfull
   // if not successfull we show an alert
   $.ajax({
      url: "http://"+window.location.host+"/api/plotdata/"+graphname,
      type: 'GET',
      cache: false,
      dataType: "json",
      success: function(data) {
         draw_chart(graphname, data);
      },
      error: function(data) {
         alert("error loading ajax data");
      }
   });

}

//----------------------------------------------------------
// draw_chart(myDiv, myData)
//----------------------------------------------------------
function draw_chart(myDiv, myData){
   
   // variables for holding information from myData
   var plotData = [];
   var plotDataSensors = [ ];
   var plotDataArray = new Array();
   
   // loop over all json data that we get and store them in an array
   for (i=0; i<myData.length ; i++) {
      plotDataArray[i] = new Object();
      plotDataArray[i].data = myData[i].temperature.slice(0);
      plotDataArray[i].name = myData[i].sensor.slice(0);
      plotData[i] = myData[i].temperature.slice(0);
      plotDataSensors[i] = myData[i].sensor.slice(0);
   }
   
   // tell Highcharts not to use UTC timezone
   Highcharts.setOptions({
      global: {
         useUTC: false
      }
   });

   // now generate the chart and display it
   new Highcharts.Chart({

      chart: {
         type: 'line',
         zoomType: 'x',
         renderTo: myDiv
      },
      title: {
         text: 'Temperatures: ' + myDiv
      },
      xAxis: {
         type: 'datetime',
         title: 'Date',
         maxZoom: 1800000
      },
      yAxis: {
         title: {
            text: 'C'
         }
      },
      credits: {
         enabled: false
      },
      plotOptions: { 
         line: {
            animation: false 
         },
         series: {
            animation: false ,
            marker: { 
               enabled: false 
            } 
         }
      },
      series:  plotDataArray
   });

}
