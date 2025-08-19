// Simple Client-Side Validation System
class SimpleValidator {
    constructor() {
        this.errors = {};
    }

    // Clear all errors
    clearErrors() {
        this.errors = {};
        document.querySelectorAll('.is-invalid').forEach(field => {
            field.classList.remove('is-invalid');
        });
        document.querySelectorAll('.invalid-feedback').forEach(feedback => {
            feedback.style.display = 'none';
        });
    }

    // Add error to a field
    addError(fieldName, message) {
        this.errors[fieldName] = message;
        const field = document.querySelector(`[name="${fieldName}"]`);
        if (field) {
            field.classList.add('is-invalid');
            const feedback = field.parentNode.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.style.display = 'block';
            }
        }
    }

    // Remove error from a field
    removeError(fieldName) {
        delete this.errors[fieldName];
        const field = document.querySelector(`[name="${fieldName}"]`);
        if (field) {
            field.classList.remove('is-invalid');
            const feedback = field.parentNode.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.style.display = 'none';
            }
        }
    }

    // Validate required field
    required(value, fieldName, displayName) {
        if (!value || value.trim() === '') {
            this.addError(fieldName, `${displayName} is required`);
            return false;
        }
        this.removeError(fieldName);
        return true;
    }

    // Validate email
    email(value, fieldName) {
        if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            this.addError(fieldName, 'Please enter a valid email address');
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate phone number (Indian format)
    phone(value, fieldName) {
        if (value && !/^[6-9]\d{9}$/.test(value.replace(/\s/g, ''))) {
            this.addError(fieldName, 'Please enter a valid 10-digit phone number');
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate minimum length
    minLength(value, min, fieldName, displayName) {
        if (value && value.length < min) {
            this.addError(fieldName, `${displayName} must be at least ${min} characters long`);
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate maximum length
    maxLength(value, max, fieldName, displayName) {
        if (value && value.length > max) {
            this.addError(fieldName, `${displayName} must not exceed ${max} characters`);
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate date
    date(value, fieldName) {
        if (value && !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            this.addError(fieldName, 'Please enter a valid date');
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate date is not in future
    dateNotFuture(value, fieldName) {
        if (value) {
            const inputDate = new Date(value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            if (inputDate > today) {
                this.addError(fieldName, 'Date cannot be in the future');
                return false;
            }
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate numeric value
    numeric(value, fieldName) {
        if (value && isNaN(parseFloat(value))) {
            this.addError(fieldName, 'Please enter a valid number');
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate positive number
    positive(value, fieldName) {
        if (value && parseFloat(value) <= 0) {
            this.addError(fieldName, 'Value must be greater than 0');
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate value is in list
    in(value, allowedValues, fieldName) {
        if (value && !allowedValues.includes(value)) {
            this.addError(fieldName, `Please select a valid option`);
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate alphabetic characters only
    alphabetic(value, fieldName) {
        if (value && !/^[a-zA-Z\s]+$/.test(value)) {
            this.addError(fieldName, 'Please enter only letters and spaces');
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate HSN code
    hsnCode(value, fieldName) {
        if (value && !/^\d{4,8}$/.test(value)) {
            this.addError(fieldName, 'HSN code must be 4-8 digits');
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Validate percentage
    percentage(value, fieldName) {
        if (value && (parseFloat(value) < 0 || parseFloat(value) > 100)) {
            this.addError(fieldName, 'Percentage must be between 0 and 100');
            return false;
        }
        if (value) this.removeError(fieldName);
        return true;
    }

    // Check if validation passed
    isValid() {
        return Object.keys(this.errors).length === 0;
    }

    // Get error messages
    getErrors() {
        return this.errors;
    }
}

// Form validation functions
const FormValidator = {
    // Validate patient registration form
    validatePatientForm(form) {
        const validator = new SimpleValidator();
        const formData = new FormData(form);
        
        // Full name validation
        const fullName = formData.get('full_name');
        validator.required(fullName, 'full_name', 'Full Name');
        if (fullName) {
            validator.alphabetic(fullName, 'full_name');
            validator.minLength(fullName, 2, 'full_name', 'Full Name');
            validator.maxLength(fullName, 100, 'full_name', 'Full Name');
        }

        // Contact number validation
        const contactNumber = formData.get('contact_number');
        validator.required(contactNumber, 'contact_number', 'Contact Number');
        if (contactNumber) {
            validator.phone(contactNumber, 'contact_number');
        }

        // Date of birth validation
        const dob = formData.get('dob');
        validator.required(dob, 'dob', 'Date of Birth');
        if (dob) {
            validator.date(dob, 'dob');
            validator.dateNotFuture(dob, 'dob');
        }

        // Gender validation
        const gender = formData.get('gender');
        validator.required(gender, 'gender', 'Gender');
        if (gender) {
            validator.in(gender, ['Male', 'Female', 'Other'], 'gender');
        }

        // Address validation
        const address = formData.get('address');
        validator.required(address, 'address', 'Address');
        if (address) {
            validator.minLength(address, 10, 'address', 'Address');
            validator.maxLength(address, 500, 'address', 'Address');
        }

        // Lead source validation (optional)
        const leadSource = formData.get('lead_source');
        if (leadSource) {
            validator.maxLength(leadSource, 100, 'lead_source', 'Lead Source');
        }

        return validator;
    },

    // Validate search form
    validateSearchForm(form) {
        const validator = new SimpleValidator();
        const formData = new FormData(form);
        
        const searchQuery = formData.get('search_query');
        validator.required(searchQuery, 'search_query', 'Search Query');
        if (searchQuery) {
            validator.minLength(searchQuery, 2, 'search_query', 'Search Query');
        }

        return validator;
    },

    // Validate visit registration form
    validateVisitForm(form) {
        const validator = new SimpleValidator();
        const formData = new FormData(form);
        
        // Doctor validation
        const doctorId = formData.get('doctor_id');
        validator.required(doctorId, 'doctor_id', 'Doctor');
        if (doctorId) {
            validator.numeric(doctorId, 'doctor_id');
            validator.positive(doctorId, 'doctor_id');
        }

        // Payment mode validation
        const paymentMode = formData.get('payment_mode');
        validator.required(paymentMode, 'payment_mode', 'Payment Mode');
        if (paymentMode) {
            validator.in(paymentMode, ['Cash', 'Card', 'UPI', 'Other'], 'payment_mode');
        }

        // Consultation fee validation
        const consultationFee = formData.get('consultation_fee');
        if (consultationFee) {
            validator.numeric(consultationFee, 'consultation_fee');
            validator.positive(consultationFee, 'consultation_fee');
        }

        return validator;
    },

    // Validate pharmacy stock form
    validatePharmacyForm(form) {
        const validator = new SimpleValidator();
        const formData = new FormData(form);
        
        // Medicine name validation
        const medicineName = formData.get('medicine_name');
        validator.required(medicineName, 'medicine_name', 'Medicine Name');
        if (medicineName) {
            validator.minLength(medicineName, 2, 'medicine_name', 'Medicine Name');
            validator.maxLength(medicineName, 200, 'medicine_name', 'Medicine Name');
        }

        // HSN code validation
        const hsnCode = formData.get('hsn_code');
        if (hsnCode) {
            validator.hsnCode(hsnCode, 'hsn_code');
        }

        // Unit type validation
        const unitType = formData.get('unit_type');
        validator.required(unitType, 'unit_type', 'Unit Type');
        if (unitType) {
            validator.in(unitType, ['capsule', 'tablet', 'other'], 'unit_type');
        }

        // Quantity validation
        const quantity = formData.get('quantity');
        validator.required(quantity, 'quantity', 'Quantity');
        if (quantity) {
            validator.numeric(quantity, 'quantity');
            validator.positive(quantity, 'quantity');
        }

        // Price validations
        const purchasePrice = formData.get('purchase_price');
        if (purchasePrice) {
            validator.numeric(purchasePrice, 'purchase_price');
        }

        const salePrice = formData.get('sale_price');
        if (salePrice) {
            validator.numeric(salePrice, 'sale_price');
        }

        // GST validation
        const gstPercent = formData.get('gst_percent');
        if (gstPercent) {
            validator.numeric(gstPercent, 'gst_percent');
            validator.percentage(gstPercent, 'gst_percent');
        }

        return validator;
    },

    // Validate doctor management form
    validateDoctorForm(form) {
        const validator = new SimpleValidator();
        const formData = new FormData(form);
        
        // Name validation
        const name = formData.get('name');
        validator.required(name, 'name', 'Doctor Name');
        if (name) {
            validator.minLength(name, 2, 'name', 'Doctor Name');
            validator.maxLength(name, 100, 'name', 'Doctor Name');
        }

        // Specialty validation
        const specialty = formData.get('specialty');
        validator.required(specialty, 'specialty', 'Specialty');
        if (specialty) {
            validator.minLength(specialty, 2, 'specialty', 'Specialty');
            validator.maxLength(specialty, 100, 'specialty', 'Specialty');
        }

        // Fees validation
        const fees = formData.get('fees');
        validator.required(fees, 'fees', 'Consultation Fees');
        if (fees) {
            validator.numeric(fees, 'fees');
            validator.positive(fees, 'fees');
        }

        return validator;
    },

    // Validate ultrasound scan form
    validateUltrasoundForm(form) {
        const validator = new SimpleValidator();
        const formData = new FormData(form);
        
        // Scan name validation
        const scanName = formData.get('scan_name');
        validator.required(scanName, 'scan_name', 'Scan Name');
        if (scanName) {
            validator.minLength(scanName, 2, 'scan_name', 'Scan Name');
            validator.maxLength(scanName, 200, 'scan_name', 'Scan Name');
        }

        // Price validation
        const price = formData.get('price');
        validator.required(price, 'price', 'Price');
        if (price) {
            validator.numeric(price, 'price');
            validator.positive(price, 'price');
        }

        // Description validation
        const description = formData.get('description');
        if (description) {
            validator.maxLength(description, 500, 'description', 'Description');
        }

        return validator;
    }
};

// Initialize validation on page load
document.addEventListener('DOMContentLoaded', function() {
    // Add real-time validation to form fields
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        // Add input event listeners for real-time validation
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                validateField(this);
            });
            
            input.addEventListener('input', function() {
                // Clear error on input if field becomes valid
                if (this.checkValidity()) {
                    this.classList.remove('is-invalid');
                    const feedback = this.parentNode.querySelector('.invalid-feedback');
                    if (feedback) {
                        feedback.style.display = 'none';
                    }
                }
            });
        });

        // Add form submission validation
        form.addEventListener('submit', function(e) {
            const validator = getValidatorForForm(form);
            if (validator) {
                validator.clearErrors();
                const result = validator(form);
                
                if (!result.isValid()) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            }
        });
    });

    // Clear errors when modals are closed
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('hidden.bs.modal', function() {
            const validator = new SimpleValidator();
            validator.clearErrors();
        });
    });
});

// Helper function to validate individual fields
function validateField(field) {
    const validator = new SimpleValidator();
    const value = field.value;
    const fieldName = field.name;
    
    // Basic HTML5 validation
    if (!field.checkValidity()) {
        field.classList.add('is-invalid');
        const feedback = field.parentNode.querySelector('.invalid-feedback');
        if (feedback) {
            feedback.style.display = 'block';
        }
        return false;
    }
    
    // Custom validation based on field type
    switch (fieldName) {
        case 'full_name':
            if (value) {
                validator.alphabetic(value, fieldName);
                validator.minLength(value, 2, fieldName, 'Full Name');
                validator.maxLength(value, 100, fieldName, 'Full Name');
            }
            break;
        case 'contact_number':
            if (value) {
                validator.phone(value, fieldName);
            }
            break;
        case 'dob':
            if (value) {
                validator.dateNotFuture(value, fieldName);
            }
            break;
        case 'address':
            if (value) {
                validator.minLength(value, 10, fieldName, 'Address');
                validator.maxLength(value, 500, fieldName, 'Address');
            }
            break;
        case 'search_query':
            if (value) {
                validator.minLength(value, 2, fieldName, 'Search Query');
            }
            break;
    }
    
    // If field is valid, remove error styling
    if (field.checkValidity() && !validator.getErrors()[fieldName]) {
        field.classList.remove('is-invalid');
        const feedback = field.parentNode.querySelector('.invalid-feedback');
        if (feedback) {
            feedback.style.display = 'none';
        }
    }
}

// Helper function to get appropriate validator for form
function getValidatorForForm(form) {
    // Check form action or form fields to determine type
    if (form.querySelector('[name="full_name"]') && form.querySelector('[name="contact_number"]')) {
        return FormValidator.validatePatientForm;
    }
    if (form.querySelector('[name="search_query"]')) {
        return FormValidator.validateSearchForm;
    }
    if (form.querySelector('[name="doctor_id"]') && form.querySelector('[name="payment_mode"]')) {
        return FormValidator.validateVisitForm;
    }
    if (form.querySelector('[name="medicine_name"]') && form.querySelector('[name="unit_type"]')) {
        return FormValidator.validatePharmacyForm;
    }
    if (form.querySelector('[name="name"]') && form.querySelector('[name="specialty"]')) {
        return FormValidator.validateDoctorForm;
    }
    if (form.querySelector('[name="scan_name"]') && form.querySelector('[name="price"]')) {
        return FormValidator.validateUltrasoundForm;
    }
    return null;
}
