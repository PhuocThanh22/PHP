/**
 * UI/UX Enhancements - JavaScript
 * Xử lý scroll effects, animations, và interactive features
 */

// ================================
// 1. NAVBAR SCROLL EFFECT
// ================================

const handleNavbarScroll = () => {
    const mainHeader = document.querySelector('.main-header');
    
    if (!mainHeader) return;
    
    window.addEventListener('scroll', () => {
        if (window.scrollY > 50) {
            mainHeader.classList.add('scrolled');
        } else {
            mainHeader.classList.remove('scrolled');
        }
    });
};

// ================================
// 2. SCROLL ANIMATION - Fade-in elements
// ================================

const observeElements = () => {
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-on-scroll');
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);
    
    // Quan sát tất cả các product items, cards, sections
    document.querySelectorAll('.product-item, .service-card, .category-card, .section-title').forEach(el => {
        observer.observe(el);
    });
};

// ================================
// 3. BUTTON RIPPLE EFFECT
// ================================

const addRippleEffect = () => {
    document.querySelectorAll('.btn, button').forEach(button => {
        button.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;
            
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            ripple.classList.add('ripple');
            
            // Remove ripple element after animation
            ripple.addEventListener('animationend', () => {
                ripple.remove();
            });
            
            this.appendChild(ripple);
        });
    });
};

// ================================
// 4. SMOOTH SCROLL EFFECT
// ================================

const smoothScrollInit = () => {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
};

// ================================
// 5. LAZY LOAD IMAGES
// ================================

const lazyLoadImages = () => {
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src || img.src;
                img.classList.add('fade-in');
                observer.unobserve(img);
            }
        });
    }, {
        threshold: 0.1
    });
    
    document.querySelectorAll('img[data-src]').forEach(img => {
        imageObserver.observe(img);
    });
};

// ================================
// 6. COUNTER ANIMATION - Cho số liệu
// ================================

const animateCounters = () => {
    const counterObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !entry.target.classList.contains('counted')) {
                const counters = entry.target.querySelectorAll('[data-target]');
                
                counters.forEach(counter => {
                    const target = parseInt(counter.getAttribute('data-target'));
                    const increment = target / 100;
                    let current = 0;
                    
                    const updateCount = () => {
                        if (current < target) {
                            current += increment;
                            counter.textContent = Math.floor(current);
                            setTimeout(updateCount, 20);
                        } else {
                            counter.textContent = target;
                        }
                    };
                    
                    updateCount();
                });
                
                entry.target.classList.add('counted');
                counterObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });
    
    document.querySelectorAll('.stats-container, .counters').forEach(el => {
        counterObserver.observe(el);
    });
};

// ================================
// 7. ACTIVE NAV LINK ON SCROLL
// ================================

const updateActiveNavLink = () => {
    window.addEventListener('scroll', () => {
        let current = '';
        
        document.querySelectorAll('section').forEach(section => {
            const sectionTop = section.offsetTop;
            const sectionHeight = section.clientHeight;
            
            if (window.pageYOffset >= sectionTop - 200) {
                current = section.getAttribute('id');
            }
        });
        
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href')?.includes(current)) {
                link.classList.add('active');
            }
        });
    });
};

// ================================
// 8. PRODUCT CARD HOVER EFFECT
// ================================

const enhanceProductCards = () => {
    document.querySelectorAll('.product-image-small, .product-card, .service-card').forEach(card => {
        // Add classes if not present
        if (!card.classList.contains('product-item')) {
            card.classList.add('product-item');
        }
        
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-8px) scale(1.02)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });
};

// ================================
// 9. FORM INPUT ENHANCEMENT
// ================================

const enhanceFormInputs = () => {
    document.querySelectorAll('.form-control').forEach(input => {
        // Float label effect
        if (input.placeholder) {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                if (!this.value) {
                    this.parentElement.classList.remove('focused');
                }
            });
        }
    });
};

// ================================
// 10. TOAST NOTIFICATIONS
// ================================

const showToast = (message, type = 'info', duration = 3000) => {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 16px 24px;
        background: ${type === 'success' ? '#4CAF50' : type === 'error' ? '#f44336' : '#2196F3'};
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        font-weight: 500;
        animation: slideUp 0.3s ease-out;
        z-index: 10000;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideDown 0.3s ease-out forwards';
        setTimeout(() => toast.remove(), 300);
    }, duration);
};

// ================================
// 11. PAGE LOAD ANIMATION
// ================================

const pageLoadAnimation = () => {
    // Fade in body khi trang load xong
    document.body.style.opacity = '0';
    
    window.addEventListener('load', () => {
        document.body.style.transition = 'opacity 0.5s ease-out';
        document.body.style.opacity = '1';
    });
};

// ================================
// 12. INITIALIZE ALL ENHANCEMENTS
// ================================

const initEnhancements = () => {
    console.log('Initializing UI/UX Enhancements...');
    
    // Chờ DOM sẵn sàng
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            handleNavbarScroll();
            observeElements();
            addRippleEffect();
            enhanceProductCards();
            enhanceFormInputs();
            animateCounters();
            updateActiveNavLink();
            pageLoadAnimation();
        });
    } else {
        handleNavbarScroll();
        observeElements();
        addRippleEffect();
        enhanceProductCards();
        enhanceFormInputs();
        animateCounters();
        updateActiveNavLink();
        pageLoadAnimation();
    }
};

// ================================
// 13. DEBOUNCE FUNCTION
// ================================

const debounce = (func, wait) => {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
};

// ================================
// 14. THROTTLE FUNCTION
// ================================

const throttle = (func, limit) => {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
};

// Start enhancements khi script load
initEnhancements();

// Export functions để dùng ở nơi khác nếu cần
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        showToast,
        debounce,
        throttle,
        initEnhancements
    };
}
