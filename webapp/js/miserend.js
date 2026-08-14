$(document).ready(function() {

    $('#selectEgyhazmegye').on('change', function() {
        $('.selectEspereskeruletDiocese').hide();
        $('.selectEspereskeruletDiocese').attr('disabled','disabled');
        $('#selectEspereskeruletDiocese' + this.value ).show();
        $('#selectEspereskeruletDiocese' + this.value ).removeAttr('disabled')
    });

    $('#selectOrszag').on('change', function() {
        $('.selectMegyeCountry').hide();
        $('.selectMegyeCountry').attr('disabled','disabled');
        $('#selectMegyeCountry' + this.value ).show();
        $('#selectMegyeCountry' + this.value ).removeAttr('disabled')
        
        $('.selectVarosCounty').hide();
        $('.selectVarosCounty').attr('disabled','disabled');
        $('#selectVarosCounty' + $(this).val() + "-" + $('#selectMegyeCountry' + this.value ).val() ).show();
        $('#selectVarosCounty' + $(this).val() + "-" + $('#selectMegyeCountry' + this.value ).val() ).removeAttr('disabled')
    });

    $('.selectMegyeCountry').on('change', function() {
        $('.selectVarosCounty').hide();
        $('.selectVarosCounty').attr('disabled','disabled');
        
        $('#selectVarosCounty' + $(this).attr('data') + "-" + $(this).val() ).show();
        $('#selectVarosCounty' + $(this).attr('data') + "-" + $(this).val() ).removeAttr('disabled');
    });

  $(document).on('click','a.ajax',function(){
    console.log('click');
    var ezez = $(this);
    var url = $(this).attr('href');
      $.ajax({
            url: url,
            success: function( data ) {
              console.log(data);
              ezez.html('<span title="' + data + '" class="fa-solid fa-check green"></span>');
            },
            error: function( data ) {
              ezez.html('<i class="fa-solid fa-xmark red"></i>');
            }
          });
    return false;
  });


   


  $(document).on('click','.javitva',function(){  
            console.log('ok');
            //event.preventDefault();
            $( this ).nextAll(" .alap:first ").toggle()
  });

  $('[title]').tooltip();

 $( ".emailmenu" ).menu();

        /* #724: itt két Google Analytics-esemény ült, de az `analytics.js` évek óta nincs
           betöltve — a `ga` így NEM létezik, és mindkét handler ReferenceError-t dobott,
           MIELŐTT a `submit()`-hoz ért volna. A preventDefault viszont már lefutott, tehát
           ezek az űrlapok csak azért működnek ma, mert a hivatkozott azonosítók sem
           léteznek már.

           A keresőkifejezéseket mostantól szerveroldalon számoljuk (l. \Stats), sütire és
           kimenő kérésre nincs szükség hozzá. */

    $('#password2').on('input', function() { 
        if($('#password1').val() != $(this).val() || $(this).val() == '') {
              $('#password2').parent().find('.form-control-feedback').addClass("fa-solid fa-triangle-exclamation").removeClass("fa-solid fa-check");
              $('#password2').parent().addClass("has-error").removeClass("has-success");

              //$('#passwordcheck').attr("title","A két jelszó nem egyezik!");
        } else {
              $('#password2').parent().find('.form-control-feedback').removeClass("fa-triangle-exclamation").addClass("fa-check");
              $('#password2').parent().removeClass("has-error").addClass("has-success");

              //$('#password2').parent().find('.form-control-feedback').attr("title","Minden rendben!");
        }
    });
    $('#password1').on('input', function() { 
        if($('#password2').val() != $(this).val() || $(this).val() == '') {
              $('#password2').parent().find('.form-control-feedback').addClass("fa-triangle-exclamation").removeClass("fa-check");
              $('#password2').parent().addClass("has-error").removeClass("has-success");

              //$('#passwordcheck').attr("title","A két jelszó nem egyezik!");
        } else {
              $('#password2').parent().find('.form-control-feedback').removeClass("fa-triangle-exclamation").addClass("fa-check");
              $('#password2').parent().removeClass("has-error").addClass("has-success");

              //$('#passwordcheck').attr("title","Minden rendben!");
        }
    });

    $('#username').on('input', function() { 

        $.ajax({
            url: "/ajax/checkusername",
            dataType: "text",
            data: {
              text: this.value
            },
            success: function( data ) {
              if(data == 0) {
                $('#username').parent().find('.form-control-feedback').addClass("fa-triangle-exclamation").removeClass("fa-check");
                $('#username').parent().addClass("has-error").removeClass("has-success");
              } else {
                $('#username').parent().find('.form-control-feedback').removeClass("fa-triangle-exclamation").addClass("fa-check");
                $('#username').parent().removeClass("has-error").addClass("has-success");
              }
              console.log(data);
              console.log('ok');
            },
            error: function( data ) {
              console.log(data);
              console.log('1err');
            }
          });


      });

  });

  $(document).on('click','.massinfo',function(){    
      //$( this ).parent().find('.massfullinfo').toggle('slow');
      $( this ).nextAll('.massfullinfo:first').toggle('slow');
      
  });

  // favorites
  $(document).on('click','.star-favorite',function(){
    var $this= $(this);

    if($(this).hasClass('grey')) var method = 'add';
    else var method = 'del';
    var tid = $(this).attr("data-tid");

    $.ajax({
       type:"POST",
       url:"/ajax/favorite",
       data:"tid="+tid+"&method="+method,
       success:function(response){
          // Frissíts minden star-favorite elemet ugyanannak az objektumnak (data-tid alapján)
          $(".star-favorite[data-tid='" + tid + "']").each(function() {
            $(this).toggleClass("grey yellow");
            if($(this).hasClass('grey')) $(this).attr('title', 'Kattintásra hozzáadás a kedvencekhez.');
            else $(this).attr('title', 'Kattintásra törlés a kedvencek közül.');
          });
       },
    });
  
  });


   $(document).on('click','.reliable',function(){

      if($(this).hasClass('check')) {
          if($(this).hasClass('lightgrey')) {
                var reliable = 'i';       
          } else {
                var reliable = '?';       
          }
      } else if($(this).hasClass('alert')) {
          if($(this).hasClass('lightgrey')) {
                var reliable = 'n';       
          } else {
                var reliable = '?';       
          }
      } 

      var rid = $(this).parent().parent().attr("data-rid");
      var here = $(this);

      $.ajax({
             type:"POST",
             url:"/ajax/switchreliable",
             data:"rid="+rid+"&reliable="+reliable,
             dataType: "text",
             success:function(response){
                 console.log('hse');
                 console.log(response);
              if(response == 'ok') {
                  console.log('meget');
                if(here.hasClass('check')) {
                    if(here.hasClass('lightgrey')) {
                          here.parent().parent().find('.alert').removeClass('red');
                          here.parent().parent().find('.alert').addClass('lightgrey')
                    } 
                    here.toggleClass("lightgrey green");
                } else if(here.hasClass('alert')) {
                    if(here.hasClass('lightgrey')) {
                          here.parent().parent().find('.check').removeClass('green');
                          here.parent().parent().find('.check').addClass('lightgrey')
                    } else {
                    }
                    here.toggleClass("lightgrey red");
                } 

                var email = here.parent().parent().attr('data-email');
                if(email !== '') {
                    $("[data-email='" + email +"']").each(function() {
                        if(reliable == 'i') {
                            $(this).find('.check').removeClass('lightgrey').addClass('green');
                            $(this).find('.alert').removeClass('red').addClass('lightgrey');
                        } else if (reliable == 'n') {
                            $(this).find('.check').removeClass('green').addClass('lightgrey');
                            $(this).find('.alert').removeClass('lightgrey').addClass('red');
                        } else if (reliable == '?') {
                            $(this).find('.check').removeClass('green').addClass('lightgrey');
                            $(this).find('.alert').removeClass('red').addClass('lightgrey');
                        }    
                    });
                }
            }
            }, 
        });        
  
/* */
});


	function OpenNewWindow(url, x, y) {
      var options = "toolbar=no,menubar=no,scrollbars=no,resizable=yes,width=" + x + ",height=" + y;
      msgWindow=window.open(url,"", options);
	}

	function OpenScrollWindow(url, x, y) {
	     var options = "toolbar=no,menubar=no,scrollbars=yes,resizable=yes,width=" + x + ",height=" + y;
	     msgWindow=window.open(url,"", options);
	}

	function goBackWithoutQ() {
	  var params = new URLSearchParams(window.location.search);
	  params.delete('q');
	  var newUrl = '/' + (params.toString() ? '?' + params.toString() : '');
    console.log(newUrl);
	  window.location.href = newUrl;
	  return false;
	}
