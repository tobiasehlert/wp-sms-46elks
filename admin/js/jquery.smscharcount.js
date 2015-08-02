/*
 * jQuery SMS Character Counter 
 * https://github.com/texh/jquery-smscharcount
 * jarrod@linahan.id.au / http://texh.net
 */
;(function ( $, window, document, undefined ) {

  // Create the defaults once
  var pluginName = 'smsCharCount',
  defaults = { },

  // SMS 1-count chars
  //!"#$%&'()*+,-0/0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz¡£¤¥§¿ÄÅÆÇÉÑÖØÜßàäåæèéìñòöøùü 
  //
  // SMS 2-count chars
  // ^{}\[~]|€ and \r\n?
  single_chars = [
    32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45,
    46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 
    59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71, 
    72, 73, 74, 75, 76, 77, 78, 79, 80, 81, 82, 83, 84, 
    85, 86, 87, 88, 89, 90, 95, 97, 98, 99, 100, 101, 
    102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 
    112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 
    122, 161, 163, 164, 165, 167, 191, 196, 197, 198, 
    199, 201, 209, 214, 216, 220, 223, 224, 228, 229, 
    230, 232, 233, 236, 241, 242, 246, 248, 249, 252
  ],
/**
// removed for wp-sms-46elks
    multi_chars = [10, 13, 47, 91, 92, 93, 94, 123, 124, 125, 126, 128, 8364],
*/

  messageLength = {
    reg: 160, unicode: 70
  },

  multiMessageLength = {
/**
// removed for wp-sms-46elks
    reg: 153, unicode: 67
*/
    reg: 160, unicode: 70
  };

  // The actual plugin constructor
  function smsCharCount( element, options ) {
    this.element = $(element);
    this.options = $.extend( {}, defaults, options) ;

    this._defaults = defaults;
    this._name = pluginName;

    this.init();
  }

  smsCharCount.prototype = {

    init: function() {
      var $this = this;
      this.element.keyup(function(){
        var chars      = $(this).val(),
            arr_chars  = chars.split(''),
            is_unicode = false,
            count      = 0;

        $.each(arr_chars, function(i, l){
          var ascii_val = parseInt(l.charCodeAt(0));

          // Normal characters
          if ($.inArray(ascii_val, single_chars) !== -1) { 
            count++; 
          // Wide characters
/**
// removed for wp-sms-46elks
          } else if ($.inArray(ascii_val, multi_chars) !== -1) {
            count += 2;
*/
          // Set unicode to true, count however many chars there are and break the loop
          } else {
            count = chars.length;
            is_unicode = true;
            return false;
          }
        });

        var per_message = messageLength[is_unicode ? "unicode" : "reg"];
        if (count > per_message) {
          per_message = multiMessageLength[is_unicode ? "unicode" : "reg"];
        }
        messages = Math.ceil(count / per_message);
        remaining = (per_message * messages) - count;

        if ($this.options.onUpdate) {
          $this.options.onUpdate({
            charCount: count,
            charRemaining: remaining,
            messageCount: messages,
            isUnicode: is_unicode
          });
        }

      });
    }
  };

  // Wrapper around the constructor preventing against multiple instantiations
  $.fn[pluginName] = function ( options ) {
    return this.each(function () {
      if (!$.data(this, "plugin_" + pluginName)) {
        $.data(this, "plugin_"  + pluginName,
        new smsCharCount( this, options ));
      }
    });
  };

})( jQuery, window, document );