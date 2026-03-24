/**
 * Dynamic Page Loader
 * Load HTML content vào div#content mà không cần reload trang
 */

const loadPage = (pageName) => {
  const contentDiv = document.getElementById('content');
  const heroContent = document.getElementById('hero-content');
  const homeContent = document.getElementById('home-content');
  
  if (!contentDiv) {
    console.error('Div #content không tồn tại!');
    return;
  }

  // Ẩn hero-content và home-content nếu là trang khác (không phải home)
  if (heroContent) {
    if (pageName === 'cart' || pageName === 'shop' || pageName === 'about' || pageName === 'contact' || pageName === 'user' || pageName === 'favorites' || pageName === 'services-spa' || pageName === 'services-hotel') {
      heroContent.style.display = 'none';
    } else {
      heroContent.style.display = 'flex';
    }
  }

  // Ẩn home-content khi load trang khác
  if (homeContent) {
    if (pageName === 'cart' || pageName === 'shop' || pageName === 'about' || pageName === 'contact' || pageName === 'user' || pageName === 'favorites' || pageName === 'services-spa' || pageName === 'services-hotel') {
      homeContent.style.display = 'none';
    } else {
      homeContent.style.display = 'block';
    }
  }

  // Hiển thị loading
  contentDiv.innerHTML = '<div class="loading" style="text-align: center; padding: 40px;"><p>Đang tải...</p></div>';

  // Fetch HTML file
  fetch(`./pages/${pageName}.html`)
    .then(response => {
      if (!response.ok) {
        throw new Error(`Lỗi: ${response.status}`);
      }
      return response.text();
    })
    .then(html => {
      // Xóa các thẻ html, body, head không cần thiết, chỉ lấy nội dung
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      
      // Extract scripts trước khi lấy body content
      const scripts = doc.querySelectorAll('script');
      const scriptContents = [];
      scripts.forEach(script => {
        if (script.textContent) {
          scriptContents.push(script.textContent);
        }
      });
      
      // Lấy toàn bộ nội dung từ body (loại bỏ scripts)
      const bodyContent = doc.body.innerHTML;
      
      // Loại bỏ script tags khỏi bodyContent
      const tempDiv = document.createElement('div');
      tempDiv.innerHTML = bodyContent;
      const scriptTags = tempDiv.querySelectorAll('script');
      scriptTags.forEach(tag => tag.remove());
      
      contentDiv.innerHTML = tempDiv.innerHTML;
      
      // Khôi phục Feather Icons nếu có
      if (typeof feather !== 'undefined') {
        feather.replace();
      }

      // Execute extracted scripts
      scriptContents.forEach(scriptContent => {
        try {
          eval(scriptContent);
        } catch (error) {
          console.error('Error executing script:', error);
        }
      });

      // Update wishlist and cart badges
      if (typeof updateWishlistIcons === 'function') {
        updateWishlistIcons();
      }
      if (typeof updateCartBadge === 'function') {
        updateCartBadge();
      }

      // Update active nav link
      if (typeof setActiveNavLink === 'function') {
        setActiveNavLink(pageName);
      }

      // Nếu là trang cart, gọi loadCart() sau một chút delay để đảm bảo script đã chạy
      if (pageName === 'cart') {
        setTimeout(() => {
          if (typeof window.loadCart === 'function') {
            window.loadCart();
          }
        }, 50);
      }
      
      // Scroll lên đầu trang
      window.scrollTo(0, 0);
    })
    .catch(error => {
      contentDiv.innerHTML = `<div class="error" style="color: red; padding: 20px;">Lỗi tải trang: ${error.message}</div>`;
      console.error('Lỗi fetch:', error);
    });
};

// Cách sử dụng: loadPage('cart') - sẽ load ./pages/cart.html
/**
 * Load News Article
 * Load bài viết chi tiết từ news-detail.html với article ID
 */
const loadNewsArticle = (articleId) => {
  const contentDiv = document.getElementById('content');
  
  if (!contentDiv) {
    console.error('Div #content không tồn tại!');
    return;
  }

  // Hiển thị loading
  contentDiv.innerHTML = '<div class="loading" style="text-align: center; padding: 40px;"><p>Đang tải...</p></div>';

  // Fetch HTML file
  fetch(`./pages/news-detail.html`)
    .then(response => {
      if (!response.ok) {
        throw new Error(`Lỗi: ${response.status}`);
      }
      return response.text();
    })
    .then(html => {
      // Xóa các thẻ html, body, head không cần thiết, chỉ lấy nội dung
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      
      // Lấy toàn bộ nội dung từ body
      const bodyContent = doc.body.innerHTML;
      contentDiv.innerHTML = bodyContent;
      
      // Set article ID vào content div để có thể tham chiếu
      contentDiv.setAttribute('data-article-id', articleId);
      
      // Khôi phục Feather Icons nếu có
      if (typeof feather !== 'undefined') {
        feather.replace();
      }
      
      // Scroll lên đầu trang
      window.scrollTo(0, 0);
    })
    .catch(error => {
      contentDiv.innerHTML = `<div class="error" style="color: red; padding: 20px;">Lỗi tải trang: ${error.message}</div>`;
      console.error('Lỗi fetch:', error);
    });
};

// Cách sử dụng: loadNewsArticle(1) - sẽ load bài viết thứ 1