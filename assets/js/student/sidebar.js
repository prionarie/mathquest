document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    const logo = document.querySelector('.logo');
    const mainContent = document.querySelector('.main-content');
    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    
    if (isCollapsed) {
        sidebar.classList.add('collapsed');
    }

    logo.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        sidebar.classList.toggle('collapsed');

        const isNowCollapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('sidebarCollapsed', isNowCollapsed);

        const icon = this.querySelector('.logo-icon');
        icon.style.transform = 'scale(1.3)';
        setTimeout(() => {
            icon.style.transform = '';
        }, 300);
    });
    
    function handleResponsive() {
        if (window.innerWidth <= 768) {
            if (!localStorage.getItem('sidebarCollapsed')) {
                sidebar.classList.add('collapsed');
                localStorage.setItem('sidebarCollapsed', 'true');
            }
        }
    }
    
    handleResponsive();
    
    window.addEventListener('resize', handleResponsive);
    
    document.addEventListener('click', function(event) {
        const isMobile = window.innerWidth <= 768;
        const isSidebar = sidebar.contains(event.target);
        const isLogo = logo.contains(event.target);
        
        if (isMobile && !isSidebar && !isLogo && !sidebar.classList.contains('collapsed')) {
            sidebar.classList.add('collapsed');
            localStorage.setItem('sidebarCollapsed', 'true');
        }
    });
});