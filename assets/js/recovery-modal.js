/* recovery-modal.js - Account Recovery Modal Handler */
(function (w, d, $) {
  "use strict";

  const API_KEY_PATTERN = /^4l_(live|test)_[a-zA-Z0-9]+$/;

  /* ---------- Open Modal ---------- */
  function openRecoveryModal() {
    const $modal = $("#recovery-modal");
    $modal.fadeIn(200);
    $("#recovery-api-key").focus();
    
    // Reset form
    $("#recovery-api-key").val("");
    $("#recovery-api-key-error").text("");
    $("#recovery-result").hide().removeClass("error loading");
    $("#recovery-success").hide();
    $(".recovery-modal-body > *:not(#recovery-success)").show();
    $("#recovery-submit").prop("disabled", false);
  }

  /* ---------- Close Modal ---------- */
  function closeRecoveryModal() {
    $("#recovery-modal").fadeOut(200);
  }

  /* ---------- Validate API Key Format ---------- */
  function validateApiKey(apiKey) {
    if (!apiKey || apiKey.trim() === "") {
      return "Please enter an API key";
    }
    
    if (!API_KEY_PATTERN.test(apiKey.trim())) {
      return "Invalid API key format. Must start with 4l_live_ or 4l_test_";
    }
    
    return null;
  }

  /* ---------- Submit Recovery ---------- */
  function submitRecovery() {
    const apiKey = $("#recovery-api-key").val().trim();
    const $errorField = $("#recovery-api-key-error");
    const $result = $("#recovery-result");
    const $submitBtn = $("#recovery-submit");
    
    // Clear previous errors
    $errorField.text("");
    $result.hide().removeClass("error loading");
    
    // Validate
    const validationError = validateApiKey(apiKey);
    if (validationError) {
      $errorField.text(validationError);
      return;
    }
    
    // Show loading
    $result
      .addClass("loading")
      .html('<div class="spinner is-active"></div> Validating API key and reconnecting site...')
      .show();
    
    $submitBtn.prop("disabled", true);
    
    // Make AJAX request
    $.ajax({
      url: w.agentHubData.ajaxUrl,
      method: "POST",
      data: {
        action: "agent_hub_recover_site",
        nonce: w.agentHubData.nonce,
        api_key: apiKey,
      },
      timeout: 30000,
    })
      .done(function (response) {
        if (response.success && response.data) {
          // Show success state
          $(".recovery-modal-body > *:not(#recovery-success)").hide();
          
          const siteInfo = `
            <p><strong>Site Name:</strong> ${response.data.site_name}</p>
            <p><strong>Site ID:</strong> ${response.data.site_id}</p>
            <p><strong>Default Price:</strong> ${response.data.default_price} ${response.data.default_currency}</p>
          `;
          
          $("#recovery-site-info").html(siteInfo);
          $("#recovery-success").fadeIn(300);
          
          // Show toast
          if (w.showToast) {
            w.showToast("Success", "Site reconnected successfully!", "success");
          }
          
          // Auto-close and reload after 3 seconds
          setTimeout(function () {
            closeRecoveryModal();
            location.reload();
          }, 3000);
          
        } else {
          // Show error
          const errorMsg = response.data?.message || "Failed to reconnect site. Please check your API key.";
          $result
            .removeClass("loading")
            .addClass("error")
            .html(`<strong>Error:</strong> ${errorMsg}`)
            .show();
          $submitBtn.prop("disabled", false);
        }
      })
      .fail(function (xhr, status, error) {
        console.error("Recovery AJAX error:", error);
        $result
          .removeClass("loading")
          .addClass("error")
          .html("<strong>Error:</strong> Network error. Please check your connection and try again.")
          .show();
        $submitBtn.prop("disabled", false);
      });
  }

  /* ---------- Initialize ---------- */
  $(document).ready(function () {
    // Open modal button
    $("#open-recovery-modal").on("click", function (e) {
      e.preventDefault();
      openRecoveryModal();
    });
    
    // Close modal buttons
    $("#recovery-modal-close, #recovery-cancel").on("click", function (e) {
      e.preventDefault();
      closeRecoveryModal();
    });
    
    // Close on background click
    $("#recovery-modal").on("click", function (e) {
      if ($(e.target).is("#recovery-modal")) {
        closeRecoveryModal();
      }
    });
    
    // Submit button
    $("#recovery-submit").on("click", function (e) {
      e.preventDefault();
      submitRecovery();
    });
    
    // Enter key in input
    $("#recovery-api-key").on("keypress", function (e) {
      if (e.which === 13) {
        e.preventDefault();
        submitRecovery();
      }
    });
    
    // Close on Escape key
    $(document).on("keydown", function (e) {
      if (e.key === "Escape" && $("#recovery-modal").is(":visible")) {
        closeRecoveryModal();
      }
    });
    
    console.log("[recovery-modal] Account recovery modal initialized");
  });
})(window, document, jQuery);
