// Global application JavaScript

// Initialize tooltips and popovers
document.addEventListener('DOMContentLoaded', function() {
    // Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Bootstrap popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
});

// Utility functions
const Utils = {
    formatDuration: function(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        
        if (hours > 0) {
            return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        } else {
            return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }
    },
    
    formatPhoneNumber: function(phone) {
        const cleaned = phone.replace(/\D/g, '');
        
        if (cleaned.length === 11 && cleaned[0] === '1') {
            return `+1 (${cleaned.slice(1, 4)}) ${cleaned.slice(4, 7)}-${cleaned.slice(7)}`;
        } else if (cleaned.length === 10) {
            return `(${cleaned.slice(0, 3)}) ${cleaned.slice(3, 6)}-${cleaned.slice(6)}`;
        }
        
        return phone;
    },
    
    showNotification: function(message, type = 'info') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    },
    
    confirmAction: function(message, callback) {
        if (confirm(message)) {
            callback();
        }
    }
};

// API helper functions
const API = {
    request: function(url, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json'
            }
        };
        
        return fetch(url, { ...defaultOptions, ...options })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .catch(error => {
                Utils.showNotification(`API Error: ${error.message}`, 'danger');
                throw error;
            });
    },
    
    campaigns: {
        getAll: function(filters = {}) {
            const params = new URLSearchParams(filters);
            return API.request(`api/campaigns.php?${params}`);
        },
        
        getById: function(id) {
            return API.request(`api/campaigns.php?id=${id}`);
        },
        
        start: function(id) {
            return API.request('api/campaigns.php', {
                method: 'POST',
                body: JSON.stringify({ action: 'start', id: id })
            });
        },
        
        pause: function(id) {
            return API.request('api/campaigns.php', {
                method: 'POST',
                body: JSON.stringify({ action: 'pause', id: id })
            });
        },
        
        create: function(data) {
            return API.request('api/campaigns.php', {
                method: 'POST',
                body: JSON.stringify({ action: 'create', ...data })
            });
        },
        
        update: function(id, data) {
            return API.request('api/campaigns.php', {
                method: 'PUT',
                body: JSON.stringify({ id: id, ...data })
            });
        },
        
        delete: function(id) {
            return API.request(`api/campaigns.php?id=${id}`, {
                method: 'DELETE'
            });
        }
    }
};

// Table enhancement functions
const TableUtils = {
    addSearch: function(tableId, searchInputId) {
        const table = document.getElementById(tableId);
        const searchInput = document.getElementById(searchInputId);
        
        if (!table || !searchInput) return;
        
        searchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < cells.length; j++) {
                    if (cells[j].textContent.toLowerCase().includes(filter)) {
                        found = true;
                        break;
                    }
                }
                
                row.style.display = found ? '' : 'none';
            }
        });
    },
    
    addSorting: function(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;
        
        const headers = table.getElementsByTagName('th');
        
        for (let i = 0; i < headers.length; i++) {
            headers[i].style.cursor = 'pointer';
            headers[i].addEventListener('click', function() {
                const column = i;
                const tbody = table.getElementsByTagName('tbody')[0];
                const rows = Array.from(tbody.getElementsByTagName('tr'));
                
                const isAscending = this.classList.contains('sort-asc');
                
                // Remove existing sort classes
                for (let h of headers) {
                    h.classList.remove('sort-asc', 'sort-desc');
                }
                
                // Add new sort class
                this.classList.add(isAscending ? 'sort-desc' : 'sort-asc');
                
                rows.sort((a, b) => {
                    const aValue = a.cells[column].textContent.trim();
                    const bValue = b.cells[column].textContent.trim();
                    
                    const aNum = parseFloat(aValue);
                    const bNum = parseFloat(bValue);
                    
                    let comparison = 0;
                    if (!isNaN(aNum) && !isNaN(bNum)) {
                        comparison = aNum - bNum;
                    } else {
                        comparison = aValue.localeCompare(bValue);
                    }
                    
                    return isAscending ? -comparison : comparison;
                });
                
                // Reorder rows
                rows.forEach(row => tbody.appendChild(row));
            });
        }
    }
};

// Form validation and enhancement
const FormUtils = {
    validatePhoneNumber: function(phone) {
        const cleaned = phone.replace(/\D/g, '');
        return cleaned.length >= 10 && cleaned.length <= 15;
    },
    
    validateEmail: function(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    },
    
    addPhoneNumberFormatting: function(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        
        input.addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            
            if (value.length >= 6) {
                value = value.slice(0, 3) + '-' + value.slice(3, 6) + '-' + value.slice(6, 10);
            } else if (value.length >= 3) {
                value = value.slice(0, 3) + '-' + value.slice(3);
            }
            
            this.value = value;
        });
    }
};

// Auto-refresh functionality
const AutoRefresh = {
    intervals: {},
    
    start: function(callback, intervalMs = 30000, key = 'default') {
        this.stop(key);
        this.intervals[key] = setInterval(callback, intervalMs);
    },
    
    stop: function(key = 'default') {
        if (this.intervals[key]) {
            clearInterval(this.intervals[key]);
            delete this.intervals[key];
        }
    },
    
    stopAll: function() {
        Object.keys(this.intervals).forEach(key => this.stop(key));
    }
};

// Page visibility handling
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        AutoRefresh.stopAll();
    } else {
        // Restart auto-refresh when page becomes visible again
        if (typeof window.restartAutoRefresh === 'function') {
            window.restartAutoRefresh();
        }
    }
});

// Global error handler
window.addEventListener('error', function(event) {
    console.error('Global error:', event.error);
    
    if (event.error && event.error.message && !event.error.message.includes('Script error')) {
        Utils.showNotification(`An error occurred: ${event.error.message}`, 'danger');
    }
});

// Export for global use
window.Utils = Utils;
window.API = API;
window.TableUtils = TableUtils;
window.FormUtils = FormUtils;
window.AutoRefresh = AutoRefresh;