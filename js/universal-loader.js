/**
 * Universal Loading System
 * Provides loading screens for all database operations across the application
 */

class UniversalLoader {
    constructor() {
        this.isLoading = false;
        this.loadingOverlay = null;
        this.init();
    }

    init() {
        // Create loading overlay HTML
        this.createLoadingOverlay();
        
        // Auto-attach to all forms
        this.attachToForms();
        
        // Auto-attach to AJAX requests
        this.attachToAjax();
        
        // Handle page navigation
        this.handlePageNavigation();
    }

    createLoadingOverlay() {
        // Remove existing overlay if present
        const existing = document.getElementById('universal-loading-overlay');
        if (existing) {
            existing.remove();
        }

        // Create new overlay
        const overlay = document.createElement('div');
        overlay.id = 'universal-loading-overlay';
        overlay.innerHTML = `
            <div class="loading-backdrop">
                <div class="loading-content">
                    <div class="loading-spinner">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="loading-text">
                        <h5 id="loading-title">Processing...</h5>
                        <p id="loading-message">Please wait while we save your data</p>
                        <div class="loading-progress">
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                     role="progressbar" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Add styles
        const style = document.createElement('style');
        style.textContent = `
            #universal-loading-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 9999;
                display: none;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }

            .loading-backdrop {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                backdrop-filter: blur(3px);
            }

            .loading-content {
                background: white;
                padding: 2rem;
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
                text-align: center;
                min-width: 300px;
                max-width: 400px;
                animation: loadingFadeIn 0.3s ease-out;
            }

            .loading-spinner {
                margin-bottom: 1.5rem;
            }

            .spinner-border {
                width: 3rem;
                height: 3rem;
                border: 0.4em solid #e3f2fd;
                border-right-color: #2196f3;
                border-radius: 50%;
                animation: spinner-border 0.75s linear infinite;
            }

            @keyframes spinner-border {
                to { transform: rotate(360deg); }
            }

            .loading-text h5 {
                color: #333;
                margin-bottom: 0.5rem;
                font-weight: 600;
            }

            .loading-text p {
                color: #666;
                margin-bottom: 1rem;
                font-size: 0.9rem;
            }

            .loading-progress {
                margin-top: 1rem;
            }

            .progress {
                height: 6px;
                background-color: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
            }

            .progress-bar {
                background-color: #2196f3;
                transition: width 0.3s ease;
            }

            .progress-bar-striped {
                background-image: linear-gradient(45deg, rgba(255,255,255,.15) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.15) 50%, rgba(255,255,255,.15) 75%, transparent 75%, transparent);
                background-size: 1rem 1rem;
            }

            .progress-bar-animated {
                animation: progress-bar-stripes 1s linear infinite;
            }

            @keyframes progress-bar-stripes {
                0% { background-position-x: 1rem; }
            }

            @keyframes loadingFadeIn {
                from {
                    opacity: 0;
                    transform: scale(0.9) translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: scale(1) translateY(0);
                }
            }

            .visually-hidden {
                position: absolute !important;
                width: 1px !important;
                height: 1px !important;
                padding: 0 !important;
                margin: -1px !important;
                overflow: hidden !important;
                clip: rect(0, 0, 0, 0) !important;
                white-space: nowrap !important;
                border: 0 !important;
            }
        `;

        document.head.appendChild(style);
        document.body.appendChild(overlay);
        this.loadingOverlay = overlay;
    }

    show(options = {}) {
        if (this.isLoading) return;

        const {
            title = 'Processing...',
            message = 'Please wait while we save your data',
            showProgress = true,
            timeout = 30000 // 30 seconds timeout
        } = options;

        this.isLoading = true;

        // Update content
        document.getElementById('loading-title').textContent = title;
        document.getElementById('loading-message').textContent = message;
        
        const progressContainer = this.loadingOverlay.querySelector('.loading-progress');
        progressContainer.style.display = showProgress ? 'block' : 'none';

        // Show overlay
        this.loadingOverlay.style.display = 'block';
        
        // Animate progress bar
        if (showProgress) {
            this.animateProgressBar();
        }

        // Set timeout to prevent infinite loading
        this.timeoutId = setTimeout(() => {
            this.hide();
            this.showError('Operation timed out. Please try again.');
        }, timeout);

        // Prevent scrolling
        document.body.style.overflow = 'hidden';
    }

    hide() {
        if (!this.isLoading) return;

        this.isLoading = false;
        
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
        }

        // Hide overlay
        this.loadingOverlay.style.display = 'none';
        
        // Reset progress bar
        const progressBar = this.loadingOverlay.querySelector('.progress-bar');
        progressBar.style.width = '0%';

        // Restore scrolling
        document.body.style.overflow = '';
    }

    animateProgressBar() {
        const progressBar = this.loadingOverlay.querySelector('.progress-bar');
        let width = 0;
        
        const interval = setInterval(() => {
            if (!this.isLoading) {
                clearInterval(interval);
                return;
            }

            width += Math.random() * 10;
            if (width > 90) width = 90; // Never complete automatically
            
            progressBar.style.width = width + '%';
        }, 500);
    }

    showSuccess(message = 'Operation completed successfully!', duration = 2000) {
        this.hide();
        this.showToast(message, 'success', duration);
    }

    showError(message = 'An error occurred. Please try again.', duration = 4000) {
        this.hide();
        this.showToast(message, 'error', duration);
    }

    showToast(message, type = 'info', duration = 3000) {
        // Remove existing toasts
        const existingToasts = document.querySelectorAll('.universal-toast');
        existingToasts.forEach(toast => toast.remove());

        const toast = document.createElement('div');
        toast.className = `universal-toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <div class="toast-icon">
                    ${type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ'}
                </div>
                <div class="toast-message">${message}</div>
                <button class="toast-close" onclick="this.parentElement.parentElement.remove()">&times;</button>
            </div>
        `;

        // Add toast styles if not already added
        if (!document.querySelector('#toast-styles')) {
            const toastStyle = document.createElement('style');
            toastStyle.id = 'toast-styles';
            toastStyle.textContent = `
                .universal-toast {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 10000;
                    min-width: 300px;
                    max-width: 500px;
                    animation: toastSlideIn 0.3s ease-out;
                }

                .toast-content {
                    display: flex;
                    align-items: center;
                    padding: 12px 16px;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                }

                .toast-success .toast-content {
                    background: #d4edda;
                    border: 1px solid #c3e6cb;
                    color: #155724;
                }

                .toast-error .toast-content {
                    background: #f8d7da;
                    border: 1px solid #f5c6cb;
                    color: #721c24;
                }

                .toast-info .toast-content {
                    background: #cce7ff;
                    border: 1px solid #b3d9ff;
                    color: #004085;
                }

                .toast-icon {
                    font-weight: bold;
                    font-size: 18px;
                    margin-right: 10px;
                    flex-shrink: 0;
                }

                .toast-message {
                    flex-grow: 1;
                    font-size: 14px;
                    line-height: 1.4;
                }

                .toast-close {
                    background: none;
                    border: none;
                    font-size: 20px;
                    cursor: pointer;
                    padding: 0;
                    margin-left: 10px;
                    opacity: 0.7;
                    flex-shrink: 0;
                }

                .toast-close:hover {
                    opacity: 1;
                }

                @keyframes toastSlideIn {
                    from {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
            `;
            document.head.appendChild(toastStyle);
        }

        document.body.appendChild(toast);

        // Auto remove after duration
        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.animation = 'toastSlideIn 0.3s ease-out reverse';
                setTimeout(() => toast.remove(), 300);
            }
        }, duration);
    }

    attachToForms() {
        // Auto-attach to all forms with data-loading attribute or forms that submit to database
        document.addEventListener('submit', (e) => {
            const form = e.target;
            
            // Check if form should show loading
            if (form.hasAttribute('data-loading') || 
                form.hasAttribute('data-async') ||
                this.isFormDatabaseOperation(form)) {
                
                const loadingOptions = this.getFormLoadingOptions(form);
                this.show(loadingOptions);

                // If it's a regular form submission, we'll hide loading on page change
                if (!form.hasAttribute('data-async')) {
                    // For regular forms, hide loading if submission fails
                    setTimeout(() => {
                        if (this.isLoading) {
                            this.hide();
                        }
                    }, 100);
                }
            }
        });
    }

    attachToAjax() {
        // Intercept fetch requests
        const originalFetch = window.fetch;
        window.fetch = (...args) => {
            const [url, options = {}] = args;
            
            // Check if this is a database operation
            if (this.isAjaxDatabaseOperation(url, options)) {
                const loadingOptions = this.getAjaxLoadingOptions(url, options);
                this.show(loadingOptions);
            }

            return originalFetch.apply(this, args)
                .then(response => {
                    if (this.isLoading) {
                        if (response.ok) {
                            this.showSuccess('Data saved successfully!');
                        } else {
                            this.showError('Failed to save data. Please try again.');
                        }
                    }
                    return response;
                })
                .catch(error => {
                    if (this.isLoading) {
                        this.showError('Network error. Please check your connection.');
                    }
                    throw error;
                });
        };

        // Intercept jQuery AJAX if available
        if (window.$ && $.ajaxSetup) {
            $(document).ajaxStart(() => {
                this.show({
                    title: 'Saving Data...',
                    message: 'Please wait while we process your request'
                });
            });

            $(document).ajaxStop(() => {
                if (this.isLoading) {
                    this.showSuccess('Data saved successfully!');
                }
            });

            $(document).ajaxError(() => {
                if (this.isLoading) {
                    this.showError('Failed to save data. Please try again.');
                }
            });
        }
    }

    handlePageNavigation() {
        // Hide loading on page unload
        window.addEventListener('beforeunload', () => {
            this.hide();
        });

        // Hide loading when page becomes visible again (in case of navigation back)
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && this.isLoading) {
                this.hide();
            }
        });
    }

    isFormDatabaseOperation(form) {
        const action = form.action || '';
        const method = form.method.toLowerCase();
        
        // Check if form is likely a database operation
        return method === 'post' || 
               action.includes('billing') ||
               action.includes('save') ||
               action.includes('create') ||
               action.includes('update') ||
               action.includes('delete') ||
               form.querySelector('input[type="submit"]') ||
               form.querySelector('button[type="submit"]');
    }

    isAjaxDatabaseOperation(url, options) {
        const method = (options.method || 'GET').toUpperCase();
        
        return method === 'POST' || 
               method === 'PUT' || 
               method === 'DELETE' ||
               url.includes('save') ||
               url.includes('create') ||
               url.includes('update') ||
               url.includes('billing');
    }

    getFormLoadingOptions(form) {
        const formName = form.name || form.id || 'form';
        const submitButton = form.querySelector('input[type="submit"], button[type="submit"]');
        const buttonText = submitButton ? submitButton.textContent || submitButton.value : '';

        let title = 'Processing...';
        let message = 'Please wait while we save your data';

        // Customize based on form context
        if (formName.includes('pharmacy') || buttonText.includes('pharmacy')) {
            title = 'Processing Pharmacy Bill...';
            message = 'Saving pharmacy transaction and updating inventory';
        } else if (formName.includes('lab') || buttonText.includes('lab')) {
            title = 'Processing Lab Bill...';
            message = 'Creating lab invoice and accounting entries';
        } else if (formName.includes('ultrasound') || buttonText.includes('ultrasound')) {
            title = 'Processing Ultrasound Bill...';
            message = 'Generating ultrasound invoice and reports';
        } else if (formName.includes('consultation') || buttonText.includes('consultation')) {
            title = 'Recording Consultation...';
            message = 'Creating consultation record and payment entry';
        } else if (formName.includes('patient') || buttonText.includes('patient')) {
            title = 'Saving Patient Data...';
            message = 'Updating patient information and records';
        }

        return { title, message };
    }

    getAjaxLoadingOptions(url, options) {
        let title = 'Saving Data...';
        let message = 'Please wait while we process your request';

        if (url.includes('pharmacy')) {
            title = 'Processing Pharmacy Data...';
            message = 'Updating pharmacy records and inventory';
        } else if (url.includes('lab')) {
            title = 'Processing Lab Data...';
            message = 'Saving lab results and billing information';
        } else if (url.includes('ultrasound')) {
            title = 'Processing Ultrasound Data...';
            message = 'Updating ultrasound records and reports';
        } else if (url.includes('consultation')) {
            title = 'Saving Consultation...';
            message = 'Recording consultation and payment details';
        }

        return { title, message };
    }

    // Public methods for manual control
    showLoading(options) {
        this.show(options);
    }

    hideLoading() {
        this.hide();
    }

    showSuccessMessage(message, duration) {
        this.showSuccess(message, duration);
    }

    showErrorMessage(message, duration) {
        this.showError(message, duration);
    }
}

// Initialize the universal loader when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.universalLoader = new UniversalLoader();
});

// Fallback initialization for cases where DOMContentLoaded already fired
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.universalLoader) {
            window.universalLoader = new UniversalLoader();
        }
    });
} else {
    if (!window.universalLoader) {
        window.universalLoader = new UniversalLoader();
    }
}

// Helper functions for manual usage
window.showLoading = function(options) {
    if (window.universalLoader) {
        window.universalLoader.showLoading(options);
    }
};

window.hideLoading = function() {
    if (window.universalLoader) {
        window.universalLoader.hideLoading();
    }
};

window.showSuccess = function(message, duration) {
    if (window.universalLoader) {
        window.universalLoader.showSuccessMessage(message, duration);
    }
};

window.showError = function(message, duration) {
    if (window.universalLoader) {
        window.universalLoader.showErrorMessage(message, duration);
    }
};