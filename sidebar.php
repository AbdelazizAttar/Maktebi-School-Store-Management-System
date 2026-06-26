<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">

	<!-- Boxicons -->
	<link href='https://unpkg.com/boxicons@2.0.9/css/boxicons.min.css' rel='stylesheet'>
	<!-- Font Awesome (for fallback) -->
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	<!-- My CSS -->
	<style>@import url('https://fonts.googleapis.com/css2?family=Lato:wght@400;700&family=Poppins:wght@400;500;600;700&display=swap');

		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}
		
		a {
			text-decoration: none;
		}
		
		li {
			list-style: none;
		}
		
		:root {
			--poppins: 'Poppins', sans-serif;
			--lato: 'Lato', sans-serif;
		
			--light: #F9F9F9;
			--blue: #3C91E6;
			--light-blue: #CFE8FF;
			--grey: #eee;
			--dark-grey: #AAAAAA;
			--dark: #342E37;
			--red: #DB504A;
			--yellow: #FFCE26;
			--light-yellow: #FFF2C6;
			--orange: #FD7238;
			--light-orange: #FFE0D3;
		}

		
		html {
			overflow-x: hidden;
		}
		
		body.dark {
			--light: #0C0C1E;
			--grey: #060714;
			--dark: #FBFBFB;
		}
		
		body {
			background: var(--grey);
			overflow-x: hidden;
		}
		
		
		/* SIDEBAR */
		#sidebar {
			position: fixed;
			top: 0;
			left: 0;
			width: 280px;
			height: 100%;
			background: var(--light);
			z-index: 2000;
			font-family: var(--lato);
			transition: .3s ease;
			overflow-x: hidden;
			scrollbar-width: none;
		}
		#sidebar::--webkit-scrollbar {
			display: none;
		}
		#sidebar.hide {
			width: 60px;
		}
		#sidebar .brand {
			font-size: 24px;
			font-weight: 700;
			height: 56px;
			display: flex;
			align-items: center;
			color: var(--blue);
			position: sticky;
			top: 0;
			left: 0;
			background: var(--light);
			z-index: 500;
			padding-bottom: 20px;
			box-sizing: content-box;
		}
		#sidebar .brand .bx {
			min-width: 60px;
			display: flex;
			justify-content: center;
		}
		#sidebar .side-menu {
			width: 100%;
			margin-top: 48px;
		}
		#sidebar .side-menu li {
			height: 48px;
			background: transparent;
			margin-left: 6px;
			border-radius: 48px 0 0 48px;
			padding: 4px;
		}
		#sidebar .side-menu li.active {
			background: var(--grey);
			position: relative;
		}
		#sidebar .side-menu li.active::before {
			content: '';
			position: absolute;
			width: 40px;
			height: 40px;
			border-radius: 50%;
			top: -40px;
			right: 0;
			box-shadow: 20px 20px 0 var(--grey);
			z-index: -1;
		}
		#sidebar .side-menu li.active::after {
			content: '';
			position: absolute;
			width: 40px;
			height: 40px;
			border-radius: 50%;
			bottom: -40px;
			right: 0;
			box-shadow: 20px -20px 0 var(--grey);
			z-index: -1;
		}
		#sidebar .side-menu li a {
			width: 100%;
			height: 100%;
			background: var(--light);
			display: flex;
			align-items: center;
			border-radius: 48px;
			font-size: 16px;
			color: var(--dark);
			white-space: nowrap;
			overflow-x: hidden;
		}
		#sidebar .side-menu.top li.active a {
			color: var(--blue);
		}
		#sidebar.hide .side-menu li a {
			width: calc(48px - (4px * 2));
			transition: width .3s ease;
		}
		#sidebar .side-menu li a.logout {
			color: var(--red);
		}
		#sidebar .side-menu.top li a:hover {
			color: var(--blue);
		}
		#sidebar .side-menu li a .bx {
			min-width: calc(60px  - ((4px + 6px) * 2));
			display: flex;
			justify-content: center;
		}
		/* SIDEBAR */
		
		
		
		
		
		/* CONTENT */
		#content {
			position: relative;
			width: calc(100% - 280px);
			left: 280px;
			transition: .3s ease;
		}
		#sidebar.hide ~ #content {
			width: calc(100% - 60px);
			left: 60px;
		}
		
		
		
		
		/* NAVBAR */
		#content nav {
			height: 56px;
			background: var(--light);
			padding: 0 24px;
			display: flex;
			align-items: center;
			grid-gap: 24px;
			font-family: var(--lato);
			position: sticky;
			top: 0;
			left: 0;
			z-index: 1000;
		}
		#content nav::before {
			content: '';
			position: absolute;
			width: 40px;
			height: 40px;
			bottom: -40px;
			left: 0;
			border-radius: 50%;
			box-shadow: -20px -20px 0 var(--light);
		}
		#content nav a {
			color: var(--dark);
		}
		#content nav .bx.bx-menu {
			cursor: pointer;
			color: var(--dark);
		}
		#content nav .nav-link {
			font-size: 16px;
			transition: .3s ease;
		}
		#content nav .nav-link:hover {
			color: var(--blue);
		}
		#content nav form {
			max-width: 400px;
			width: 100%;
			margin-right: auto;
		}
		#content nav form .form-input {
			display: flex;
			align-items: center;
			height: 36px;
		}
		#content nav form .form-input input {
			flex-grow: 1;
			padding: 0 16px;
			height: 100%;
			border: none;
			background: var(--grey);
			border-radius: 36px 0 0 36px;
			outline: none;
			width: 100%;
			color: var(--dark);
		}
		#content nav form .form-input button {
			width: 36px;
			height: 100%;
			display: flex;
			justify-content: center;
			align-items: center;
			background: var(--blue);
			color: var(--light);
			font-size: 18px;
			border: none;
			outline: none;
			border-radius: 0 36px 36px 0;
			cursor: pointer;
		}
		#content nav .notification {
			font-size: 20px;
			position: relative;
		}
		#content nav .notification .num {
			position: absolute;
			top: -6px;
			right: -6px;
			width: 20px;
			height: 20px;
			border-radius: 50%;
			border: 2px solid var(--light);
			background: var(--red);
			color: var(--light);
			font-weight: 700;
			font-size: 12px;
			display: flex;
			justify-content: center;
			align-items: center;
		}
		#content nav .profile img {
			width: 36px;
			height: 36px;
			object-fit: cover;
			border-radius: 50%;
		}
		#content nav .switch-mode {
			display: block;
			min-width: 50px;
			height: 25px;
			border-radius: 25px;
			background: var(--grey);
			cursor: pointer;
			position: relative;
		}
		#content nav .switch-mode::before {
			content: '';
			position: absolute;
			top: 2px;
			left: 2px;
			bottom: 2px;
			width: calc(25px - 4px);
			background: var(--blue);
			border-radius: 50%;
			transition: all .3s ease;
		}
		#content nav #switch-mode:checked + .switch-mode::before {
			left: calc(100% - (25px - 4px) - 2px);
		}
		/* NAVBAR */
		
		
		
		@media screen and (max-width: 768px) {
			#sidebar {
				width: 200px;
			}
		
			#content {
				width: calc(100% - 60px);
				left: 200px;
			}
		
			#content nav .nav-link {
				display: none;
			}
		}
		
		
		@media screen and (max-width: 576px) {
			#content nav form .form-input input {
				display: none;
			}
		
			#content nav form .form-input button {
				width: auto;
				height: auto;
				background: transparent;
				border-radius: none;
				color: var(--dark);
			}
		
			#content nav form.show .form-input input {
				display: block;
				width: 100%;
			}
			#content nav form.show .form-input button {
				width: 36px;
				height: 100%;
				border-radius: 0 36px 36px 0;
				color: var(--light);
				background: var(--red);
			}
		
			#content nav form.show ~ .notification,
			#content nav form.show ~ .profile {
				display: none;
			}
		
			#content main .box-info {
				grid-template-columns: 1fr;
			}
		
			#content main .table-data .head {
				min-width: 420px;
			}
			#content main .table-data .order table {
				min-width: 420px;
			}
			#content main .table-data .todo .todo-list {
				min-width: 420px;
			}

		}
		.imgico{
			width:18px;
			height:18px;
			
			margin-left:10px;
		}
		.text33{
			margin-left:13px;
		}
		.menu-icon {
    font-size: 24px;
    color: #333;
    cursor: pointer;
    transition: color 0.3s;
}

.menu-icon:hover {
    color: #007bff;
}

.nav-center {
    flex-grow: 1;
    text-align: center;
}

.title {
    color: #007bff;
    font-size: 28px;
    font-weight: 700;
    letter-spacing: 1px;
    font-family: 'MingLiU-ExtB';
    margin: 0;
    text-shadow: 1px 1px 2px rgba(0, 123, 255, 0.2);
}

.nav-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 8px;
}

.user-img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #007bff;
}

.username {
    color: #333;
    font-size: 16px;
    font-weight: 500;
}
.text333{
			margin-left:13px;
			
		}
		</style>

	<title>Gestionnaire Magasin</title>
</head>
<body>

	<section id="sidebar">
		<a href="#" class="brand">
			<i class='bx bxs-store'></i>
			<span class="text">Gestionnaire</span>
		</a>
		
		

		<ul class="side-menu top">
			<li class="<?= $current_page === 'admin.php' ? 'active' : '' ?>">
				<a href="admin.php">
					<i class='bx bxs-dashboard'></i>
					<span class="text">Tableau de bord</span>
				</a>
			</li>
			<li class="<?= $current_page === 'gestion_produits.php' ? 'active' : '' ?>">
				<a href="gestion_produits.php">
					<i class='bx bxs-box'></i>
					<span class="text">Produits</span>
				</a>
			</li>
			<li class="<?= $current_page === 'gestion_utilisateurs.php' ? 'active' : '' ?>">
				<a href="gestion_utilisateurs.php">
					<i class='bx bxs-user-detail'></i>
					<span class="text">Utilisateurs</span>
				</a>
			</li>
			<li class="<?= $current_page === 'historique_ventes.php' ? 'active' : '' ?>">
				<a href="historique_ventes.php">
				<span><i > <img class="imgico" src="../history.png" alt="image" srcset=""></i><span class="text33">Historique</span></span>
					
				</a>
			</li>
			
			<li class="<?= $current_page === 'rapports.php' ? 'active' : '' ?>">
				<a href="rapports.php">
					<i class='bx bxs-report'></i>
					<span class="text">Rapports</span>
				</a>
			</li>

			<li class="<?= $current_page === 'caisse.php' ? 'active' : '' ?>">
				<a href="caisse.php">
					<i class='bx bxs-credit-card'></i>
					<span class="text">Caisse</span>
				</a>
			</li>
		</ul>
		
		<ul class="side-menu">
			<li>
				<a href="../logout.php" class="logout">
					<img class="imgico" src="../switch.png" alt="image" srcset=""><span class="text333">Déconnexion</span>

				</a>
			</li>
			<img src="switch.png" alt="" srcset="">

		</ul>
	</section>

	<section id="content">
    <nav class="navbar">
        <div class="nav-left">
            <i class='bx bx-menu menu-icon'></i>
        </div>
        <div class="nav-center">
            <img src="logo.png" alt="" srcset="">
        </div>
        <div class="nav-right">
            <div class="user-info">
                <img src="bussiness-man.png" alt="Profile" class="user-img">
                <span class="username">Admin</span>
            </div>
        </div>
    </nav>
</section>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			// Toggle sidebar
			const menuBar = document.querySelector('#content nav .bx.bx-menu');
			const sidebar = document.getElementById('sidebar');
			
			menuBar.addEventListener('click', function() {
				sidebar.classList.toggle('hide');
				
				// Save state in localStorage
				if (sidebar.classList.contains('hide')) {
					localStorage.setItem('sidebarState', 'collapsed');
				} else {
					localStorage.setItem('sidebarState', 'expanded');
				}
			});
			
			// Check saved sidebar state
			if (localStorage.getItem('sidebarState') === 'collapsed') {
				sidebar.classList.add('hide');
			}
			
			// Search form toggle on mobile
			const searchButton = document.querySelector('#content nav form .form-input button');
			const searchButtonIcon = document.querySelector('#content nav form .form-input button .bx');
			const searchForm = document.querySelector('#content nav form');
			
			searchButton.addEventListener('click', function(e) {
				if(window.innerWidth < 576) {
					e.preventDefault();
					searchForm.classList.toggle('show');
					if(searchForm.classList.contains('show')) {
						searchButtonIcon.classList.replace('bx-search', 'bx-x');
					} else {
						searchButtonIcon.classList.replace('bx-x', 'bx-search');
					}
				}
			});
			
			// Dark mode toggle
			const switchMode = document.getElementById('switch-mode');
			const darkModeState = localStorage.getItem('darkMode');
			
			if (darkModeState === 'enabled') {
				document.body.classList.add('dark');
				switchMode.checked = true;
			}
			
			switchMode.addEventListener('change', function() {
				if(this.checked) {
					document.body.classList.add('dark');
					localStorage.setItem('darkMode', 'enabled');
				} else {
					document.body.classList.remove('dark');
					localStorage.setItem('darkMode', null);
				}
			});
			
			// Responsive adjustments
			function handleResponsive() {
				if(window.innerWidth < 768) {
					if(!sidebar.classList.contains('hide')) {
						sidebar.classList.add('hide');
					}
				} 
				
				if(window.innerWidth > 576 && searchForm.classList.contains('show')) {
					searchForm.classList.remove('show');
					searchButtonIcon.classList.replace('bx-x', 'bx-search');
				}
			}
			
			// Initial check
			handleResponsive();
			
			// Window resize listener
			window.addEventListener('resize', handleResponsive);
			
			// Active menu item highlighting
			const currentPath = window.location.pathname.split('/').pop();
			const menuItems = document.querySelectorAll('.side-menu.top li');
			
			menuItems.forEach(item => {
				const link = item.querySelector('a').getAttribute('href');
				if (link === currentPath) {
					item.classList.add('active');
				} else {
					item.classList.remove('active');
				}
			});
		});
	</script>
</body>