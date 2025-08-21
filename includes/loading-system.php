<?php
/**
 * Universal Loading System Include
 * Include this file at the end of any page that needs loading screens
 */
?>

<!-- Universal Loading System -->
<script src="js/universal-loader.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize loading system
    if (!window.universalLoader) {
        window.universalLoader = new UniversalLoader();
    }
    
    // Auto-detect page type and customize loading messages
    const currentPage = window.location.pathname;
    let pageConfig = {};
    
    if (currentPage.includes('pharmacy')) {
        pageConfig = {
            title: 'Processing Pharmacy Bill...',
            message: 'Saving transaction and updating inventory records',
            successMessage: 'Pharmacy bill processed successfully!',
            errorMessage: 'Failed to process pharmacy bill. Please try again.'
        };
    } else if (currentPage.includes('lab')) {
        pageConfig = {
            title: 'Processing Lab Bill...',
            message: 'Creating lab invoice and accounting entries',
            successMessage: 'Lab bill processed successfully!',
            errorMessage: 'Failed to process lab bill. Please try again.'
        };
    } else if (currentPage.includes('ultrasound')) {
        pageConfig = {
            title: 'Processing Ultrasound Bill...',
            message: 'Generating ultrasound invoice and reports',
            successMessage: 'Ultrasound bill processed successfully!',
            errorMessage: 'Failed to process ultrasound bill. Please try again.'
        };
    } else if (currentPage.includes('index') || currentPage.includes('consultation')) {
        pageConfig = {
            title: 'Recording Consultation...',
            message: 'Creating consultation record and payment entry',
            successMessage: 'Consultation recorded successfully!',
            errorMessage: 'Failed to record consultation. Please try again.'
        };
    } else {
        pageConfig = {
            title: 'Processing...',
            message: 'Please wait while we save your data',
            successMessage: 'Data saved successfully!',
            errorMessage: 'Failed to save data. Please try again.'
        };
    }
    
    // Store page config globally
    window.pageLoadingConfig = pageConfig;
    
    // Enhanced form submission handling
    document.addEventListener('submit', function(e) {
        const form = e.target;
        
        // Skip if form already has loading handled
        if (form.hasAttribute('data-loading-handled')) return;
        
        // Check if this is a database operation
        if (form.method.toLowerCase() === 'post' || 
            form.action.includes('billing') ||
            form.action.includes('save') ||
            form.querySelector('input[type="submit"]') ||
            form.querySelector('button[type="submit"]')) {
            
            // Show loading
            showLoading({
                title: pageConfig.title,
                message: pageConfig.message
            });
            
            // Mark as handled
            form.setAttribute('data-loading-handled', 'true');
            
            // For AJAX forms, we'll handle success/error in the response
            // For regular forms, loading will be hidden on page change
        }
    });
    
    // Handle AJAX responses if jQuery is available
    if (typeof $ !== 'undefined') {
        $(document).ajaxStart(function() {
            showLoading({
                title: pageConfig.title,
                message: pageConfig.message
            });
        });
        
        $(document).ajaxSuccess(function(event, xhr, settings) {
            showSuccess(pageConfig.successMessage);
        });
        
        $(document).ajaxError(function(event, xhr, settings) {
            showError(pageConfig.errorMessage);
        });
    }
    
    // Handle fetch API responses
    const originalFetch = window.fetch;
    window.fetch = function(...args) {
        const [url, options = {}] = args;
        
        // Show loading for POST requests
        if (options.method === 'POST' || options.method === 'PUT' || options.method === 'DELETE') {
            showLoading({
                title: pageConfig.title,
                message: pageConfig.message
            });
        }
        
        return originalFetch.apply(this, args)
            .then(response => {
                if (options.method === 'POST' || options.method === 'PUT' || options.method === 'DELETE') {
                    if (response.ok) {
                        showSuccess(pageConfig.successMessage);
                    } else {
                        showError(pageConfig.errorMessage);
                    }
                }
                return response;
            })
            .catch(error => {
                if (options.method === 'POST' || options.method === 'PUT' || options.method === 'DELETE') {
                    showError('Network error. Please check your connection.');
                }
                throw error;
            });
    };
});

// Helper functions for manual control
window.showPageLoading = function(customTitle, customMessage) {
    const config = window.pageLoadingConfig || {};
    showLoading({
        title: customTitle || config.title || 'Processing...',
        message: customMessage || config.message || 'Please wait...'
    });
};

window.showPageSuccess = function(customMessage) {
    const config = window.pageLoadingConfig || {};
    showSuccess(customMessage || config.successMessage || 'Operation completed successfully!');
};

window.showPageError = function(customMessage) {
    const config = window.pageLoadingConfig || {};
    showError(customMessage || config.errorMessage || 'Operation failed. Please try again.');
};
</script>

<style>
/* Additional loading styles for better integration */
.btn-loading {
    position: relative;
    pointer-events: none;
    opacity: 0.7;
}

.btn-loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    margin-left: -8px;
    margin-top: -8px;
    width: 16px;
    height: 16px;
    border: 2px solid transparent;
    border-top: 2px solid currentColor;
    border-radius: 50%;
    animation: btn-loading-spin 1s linear infinite;
}

@keyframes btn-loading-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Form loading state */
.form-loading {
    pointer-events: none;
    opacity: 0.7;
}

.form-loading input,
.form-loading select,
.form-loading textarea,
.form-loading button {
    pointer-events: none;
}
</style>