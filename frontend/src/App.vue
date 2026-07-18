<template>
  <div class="min-h-screen flex bg-gray-50">
    <AlertList />

    <!-- Sidebar (desktop) -->
    <aside
      v-if="route.name !== 'login'"
      class="hidden md:flex md:flex-col bg-[#800000] text-white shadow-2xl flex-shrink-0 transition-all duration-300 relative"
      :class="isSidebarCollapsed ? 'w-20' : 'w-64'"
    >
      <!-- Collapse Button -->
      <button 
        @click="isSidebarCollapsed = !isSidebarCollapsed" 
        class="absolute -right-3 top-8 w-6 h-6 bg-white text-[#800000] rounded-full shadow-md flex items-center justify-center hover:bg-gray-100 z-50 transition-transform"
        :class="{'rotate-180': isSidebarCollapsed}"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
      </button>
      <!-- Logo -->
      <div class="flex items-center gap-3 py-6 border-b border-white/10 transition-all duration-300" :class="isSidebarCollapsed ? 'justify-center px-2' : 'px-6'">
        <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
        </div>
        <div v-show="!isSidebarCollapsed" class="whitespace-nowrap overflow-hidden">
          <h1 class="font-bold text-lg leading-tight">E-Leave</h1>
          <p class="text-white/60 text-xs">ระบบลางานออนไลน์</p>
        </div>
      </div>

      <!-- User info -->
      <router-link to="/profile" class="block py-4 border-b border-white/10 hover:bg-white/5 transition-all duration-300 cursor-pointer" :class="isSidebarCollapsed ? 'px-2' : 'px-6'">
        <div class="flex items-center gap-3" :class="isSidebarCollapsed ? 'justify-center' : ''">
          <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0" :title="authStore.user?.name">
            {{ authStore.user?.name?.charAt(0)?.toUpperCase() }}
          </div>
          <div class="min-w-0 flex-1 whitespace-nowrap overflow-hidden" v-show="!isSidebarCollapsed">
            <p class="font-semibold text-sm truncate group-hover:text-white">{{ authStore.user?.name }}</p>
            <span class="inline-block text-xs px-2 py-0.5 rounded-full mt-0.5 truncate max-w-full" :class="roleBadgeClass">
              {{ roleLabel }}
            </span>
          </div>
          <svg v-show="!isSidebarCollapsed" class="w-4 h-4 text-white/50 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </div>
      </router-link>

      <!-- Navigation -->
      <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">
        <!-- ทุกคนเห็น: หน้าหลัก -->
        <router-link
          to="/"
          class="nav-link flex items-center"
          :class="isSidebarCollapsed ? 'justify-center px-0' : ''"
          active-class="nav-link-active"
          :title="isSidebarCollapsed ? 'หน้าหลัก' : ''"
        >
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
          </svg>
          <span v-show="!isSidebarCollapsed" class="whitespace-nowrap">หน้าหลัก</span>
        </router-link>

        <!-- ระบบลางาน Collapsible -->
        <div>
          <button
            @click="leaveMenuOpen = !leaveMenuOpen; if(isSidebarCollapsed) isSidebarCollapsed = false"
            class="nav-link w-full flex justify-between items-center"
            :class="[isLeaveRouteActive ? 'bg-white/10 text-white' : '', isSidebarCollapsed ? 'justify-center px-0' : '']"
            :title="isSidebarCollapsed ? 'ระบบลางาน' : ''"
          >
            <div class="flex items-center gap-3">
              <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
              <span v-show="!isSidebarCollapsed" class="whitespace-nowrap">ระบบลางาน</span>
            </div>
            <svg v-show="!isSidebarCollapsed" class="w-4 h-4 transition-transform duration-200 flex-shrink-0" :class="{ 'rotate-180': leaveMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>
          
          <div v-show="leaveMenuOpen && !isSidebarCollapsed" class="mt-1 space-y-1 pl-9">
            <!-- ยื่นใบลา -->
            <router-link
              to="/request"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              ยื่นใบลา
            </router-link>

            <!-- รายงาน -->
            <router-link
              to="/reports"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              รายงานสถิติการลา
            </router-link>

            <!-- รายการใบลา -->
            <router-link
              to="/leaves"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              รายการใบลาของฉัน
            </router-link>

            <!-- ปฏิทินวันลาทีม -->
            <router-link
              to="/team-calendar"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              ปฏิทินวันลาทีม
            </router-link>

            <!-- อนุมัติใบลา -->
            <router-link
              v-if="authStore.isManager || authStore.hasPermission('approvals')"
              to="/approvals"
              class="nav-link !py-2 !text-sm flex justify-between"
              active-class="nav-link-active"
            >
              <span>อนุมัติใบลา</span>
              <span v-if="pendingBadge > 0"
                class="bg-yellow-400 text-yellow-900 text-xs font-bold px-1.5 py-0.5 rounded-full">
                {{ pendingBadge }}
              </span>
            </router-link>

            <!-- อนุมัติยกเลิกใบลา -->
            <router-link
              v-if="authStore.isManager || authStore.hasPermission('cancel-approvals')"
              to="/cancel-approvals"
              class="nav-link !py-2 !text-sm flex justify-between"
              active-class="nav-link-active"
            >
              <span>อนุมัติยกเลิกใบลา</span>
              <span v-if="pendingCancelBadge > 0"
                class="bg-orange-400 text-orange-900 text-xs font-bold px-1.5 py-0.5 rounded-full">
                {{ pendingCancelBadge }}
              </span>
            </router-link>

            <!-- จัดการวันลา (HR) -->
            <router-link
              v-if="authStore.isUserManager"
              to="/admin/leaves-management"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              จัดการ/ซิงค์วันลา (HR)
            </router-link>
          </div>
        </div>

        <!-- ระบบลงเวลาออนไลน์ Collapsible -->
        <div>
          <button
            @click="attendanceMenuOpen = !attendanceMenuOpen; if(isSidebarCollapsed) isSidebarCollapsed = false"
            class="nav-link w-full flex justify-between items-center"
            :class="[isAttendanceRouteActive ? 'bg-white/10 text-white' : '', isSidebarCollapsed ? 'justify-center px-0' : '']"
            :title="isSidebarCollapsed ? 'ระบบลงเวลาออนไลน์' : ''"
          >
            <div class="flex items-center gap-3">
              <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <span v-show="!isSidebarCollapsed" class="whitespace-nowrap">ระบบลงเวลาออนไลน์</span>
            </div>
            <svg v-show="!isSidebarCollapsed" class="w-4 h-4 transition-transform duration-200 flex-shrink-0" :class="{ 'rotate-180': attendanceMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>
          
          <div v-show="attendanceMenuOpen && !isSidebarCollapsed" class="mt-1 space-y-1 pl-9">
            <!-- ลงเวลาเข้า-ออก -->
            <router-link
              to="/attendance/check-in"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              ลงเวลาปฏิบัติงาน
            </router-link>

            <!-- ประวัติการลงเวลา -->
            <router-link
              to="/attendance/history"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              ประวัติการลงเวลา
            </router-link>

            <!-- รายงานการลงเวลา (Admin) -->
            <router-link
              v-if="authStore.isAdmin || authStore.isHRHead"
              to="/admin/attendances"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              รายงานการลงเวลา
            </router-link>

            <!-- สรุปผลการปฏิบัติงาน (Admin) -->
            <router-link
              v-if="authStore.isAdmin || authStore.isHRHead"
              to="/admin/attendances/summary"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              สรุปผลการปฏิบัติงาน
            </router-link>

            <!-- สรุปผลการปฏิบัติงาน (OT) -->
            <router-link
              v-if="authStore.isAdmin || authStore.isHRHead"
              to="/admin/ot-summary"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              สรุปผลการปฏิบัติงาน (OT)
            </router-link>
          </div>
        </div>

        <!-- ระบบสารบัญอิเล็กทรอนิกส์ Collapsible -->
        <div v-if="globalSettings.enable_saraban_menu !== '0'">
          <button
            @click="sarabanMenuOpen = !sarabanMenuOpen; if(isSidebarCollapsed) isSidebarCollapsed = false"
            class="nav-link w-full flex justify-between items-center"
            :class="[isSarabanRouteActive ? 'bg-white/10 text-white' : '', isSidebarCollapsed ? 'justify-center px-0' : '']"
            :title="isSidebarCollapsed ? 'ระบบสารบัญ' : ''"
          >
            <div class="flex items-center gap-3">
              <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              <span v-show="!isSidebarCollapsed" class="whitespace-nowrap">ระบบสารบัญ</span>
            </div>
            <svg v-show="!isSidebarCollapsed" class="w-4 h-4 transition-transform duration-200 flex-shrink-0" :class="{ 'rotate-180': sarabanMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>
          
          <div v-show="sarabanMenuOpen && !isSidebarCollapsed" class="mt-1 space-y-1 pl-9">
            <!-- ลงทะเบียนหนังสือใหม่ -->
            <router-link
              v-if="authStore.isAdmin || authStore.hasPermission('saraban.document.create')"
              to="/saraban/create"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              ลงทะเบียนหนังสือใหม่
            </router-link>

            <!-- รายการเอกสารทั้งหมด -->
            <router-link
              to="/saraban"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
              :class="{ 'nav-link-active': $route.path === '/saraban' }"
            >
              ทะเบียนรับ-ส่ง (ทั้งหมด)
            </router-link>

            <!-- หนังสือรับ -->
            <router-link
              to="/saraban/incoming"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              หนังสือรับ
            </router-link>

            <!-- หนังสือส่ง -->
            <router-link
              to="/saraban/outgoing"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              หนังสือส่ง
            </router-link>

            <!-- หนังสือเวียน -->
            <router-link
              to="/saraban/circulars"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              หนังสือเวียน
            </router-link>

            <!-- รายงานสารบรรณ -->
            <router-link
              to="/saraban/reports"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              รายงานสถิติ
            </router-link>

            <!-- จัดการหมวดหมู่เอกสาร -->
            <router-link
              v-if="authStore.isAdmin || authStore.isSarabanOfficer"
              to="/saraban/types"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              จัดการหมวดหมู่เอกสาร
            </router-link>
            <!-- สายการอนุมัติ -->
            <router-link
              v-if="authStore.isAdmin || authStore.hasPermission('manage_workflow_steps')"
              to="/saraban/workflow-templates"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              สายการอนุมัติ
            </router-link>
            <!-- ตั้งค่าระบบสารบัญ -->
            <router-link
              v-if="authStore.isAdmin || authStore.hasPermission('manage_settings')"
              to="/saraban/settings"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              ตั้งค่าระบบสารบัญ
            </router-link>
          </div>
        </div>


        <!-- Divider for admin section -->
        <div v-if="authStore.hasAnyAdminMenu" class="pt-3 pb-1" :class="isSidebarCollapsed ? 'text-center' : ''">
          <p class="text-white/30 text-[10px] uppercase tracking-widest px-3" v-if="!isSidebarCollapsed">ผู้ดูแลระบบ</p>
          <div v-else class="h-px bg-white/20 mx-4 my-2"></div>
        </div>

        <!-- SuperAdmin & HR: จัดการผู้ใช้ -->
        <router-link
          v-if="authStore.isAdmin || authStore.isHRHead || authStore.hasPermission('admin-users')"
          to="/admin/users"
          class="nav-link flex items-center"
          :class="isSidebarCollapsed ? 'justify-center px-0' : ''"
          active-class="nav-link-active"
          :title="isSidebarCollapsed ? 'จัดการผู้ใช้' : ''"
        >
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          <span v-show="!isSidebarCollapsed" class="whitespace-nowrap">จัดการผู้ใช้</span>
        </router-link>

        <!-- SuperAdmin only: ตั้งค่าระบบ Collapsible -->
        <div v-if="authStore.hasAnySettingsMenu">
          <button
            @click="adminMenuOpen = !adminMenuOpen; if(isSidebarCollapsed) isSidebarCollapsed = false"
            class="nav-link w-full flex justify-between items-center"
            :class="[isAdminRouteActive ? 'bg-white/10 text-white' : '', isSidebarCollapsed ? 'justify-center px-0' : '']"
            :title="isSidebarCollapsed ? 'ตั้งค่าระบบ' : ''"
          >
            <div class="flex items-center gap-3">
              <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
              </svg>
              <span v-show="!isSidebarCollapsed" class="whitespace-nowrap">ตั้งค่าระบบ</span>
            </div>
            <svg v-show="!isSidebarCollapsed" class="w-4 h-4 transition-transform duration-200 flex-shrink-0" :class="{ 'rotate-180': adminMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>
          
          <div v-show="adminMenuOpen && !isSidebarCollapsed" class="mt-1 space-y-1 pl-9">
            <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-settings')" to="/admin/settings" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              ข้อมูลสถานศึกษา
            </router-link>
            <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-departments')" to="/admin/departments" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              จัดการแผนกวิชา
            </router-link>
            <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-workflow')" to="/admin/workflow" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              จัดการลำดับการอนุมัติ
            </router-link>
            <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-leave-types')" to="/admin/leave-types" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              ประเภทการลา
            </router-link>
            <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-fiscal-years')" to="/admin/fiscal-years" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              ปีงบประมาณ
            </router-link>
            <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-notifications')" to="/admin/notifications" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              การแจ้งเตือน
            </router-link>
            <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-settings')" to="/admin/modules" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              โมดูลระบบ
            </router-link>
          </div>
        </div>
      </nav>

      <!-- Logout -->
      <div class="px-4 py-4 border-t border-white/10">
        <div v-show="!isSidebarCollapsed" class="text-xs text-center text-white/50 mb-2 truncate">
          ออกอัตโนมัติใน: {{ formattedIdleTime }}
        </div>
        <button
          @click="confirmLogout"
          class="flex items-center gap-3 w-full px-3 py-2.5 rounded-xl text-white/70 hover:bg-red-800/60 hover:text-white transition-all duration-150 group"
          :class="isSidebarCollapsed ? 'justify-center px-0' : ''"
          :title="isSidebarCollapsed ? 'ออกจากระบบ' : ''"
        >
          <svg class="w-5 h-5 group-hover:translate-x-0.5 transition-transform flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
          </svg>
          <span v-show="!isSidebarCollapsed" class="whitespace-nowrap">ออกจากระบบ</span>
        </button>
      </div>
    </aside>

    <!-- Main content area -->
    <div class="flex-1 flex flex-col min-h-screen min-w-0">

      <!-- Mobile top bar -->
      <header
        v-if="route.name !== 'login'"
        class="md:hidden bg-[#800000] text-white px-4 py-3 flex justify-between items-center shadow-md z-10 relative"
      >
        <div class="flex items-center gap-2">
          <span class="font-bold text-lg">E-Leave</span>
        </div>
        <div class="flex items-center gap-2">
          <div class="bg-white/10 rounded-full">
            <NotificationBell />
          </div>
          <button @click="mobileMenu = !mobileMenu" class="p-1 rounded-lg hover:bg-white/20 transition-colors">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-if="!mobileMenu" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
              <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
      </header>

      <!-- Mobile menu -->
      <transition name="slide-down">
        <div v-if="mobileMenu && route.name !== 'login'" class="md:hidden bg-[#700000] text-white px-4 py-3 space-y-1 shadow-md">
          <router-link to="/" @click="mobileMenu=false"
            class="flex items-center gap-2 px-3 py-2 rounded-lg text-white/80 hover:bg-white/10"
            active-class="!bg-white/20 !text-white">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            หน้าหลัก
          </router-link>
          <!-- ระบบลางาน Collapsible -->
          <div>
            <button @click="leaveMenuOpen = !leaveMenuOpen"
              class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-white/80 hover:bg-white/10"
              :class="{ 'bg-white/20 text-white': isLeaveRouteActive }">
              <div class="flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                ระบบลางาน
              </div>
              <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': leaveMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>
            
            <div v-show="leaveMenuOpen" class="mt-1 pl-6 space-y-1">
              <router-link to="/request" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                ยื่นใบลา
              </router-link>
              <router-link to="/reports" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                รายงานสถิติการลา
              </router-link>
              <router-link to="/leaves" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                รายการใบลาของฉัน
              </router-link>
              <router-link to="/team-calendar" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                ปฏิทินวันลาทีม
              </router-link>
              <router-link v-if="authStore.isManager || authStore.hasPermission('approvals')" to="/approvals" @click="mobileMenu=false"
                class="flex items-center justify-between px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                อนุมัติใบลา
                <span v-if="pendingBadge > 0" class="bg-yellow-400 text-yellow-900 text-xs font-bold px-1.5 py-0.5 rounded-full">{{ pendingBadge }}</span>
              </router-link>
              <router-link v-if="authStore.isManager || authStore.hasPermission('cancel-approvals')" to="/cancel-approvals" @click="mobileMenu=false"
                class="flex items-center justify-between px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                อนุมัติยกเลิกใบลา
                <span v-if="pendingCancelBadge > 0" class="bg-orange-400 text-orange-900 text-xs font-bold px-1.5 py-0.5 rounded-full">{{ pendingCancelBadge }}</span>
              </router-link>
            </div>
          </div>
          <!-- ระบบลงเวลาออนไลน์ Collapsible (Mobile) -->
          <div>
            <button @click="attendanceMenuOpen = !attendanceMenuOpen"
              class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-white/80 hover:bg-white/10"
              :class="{ 'bg-white/20 text-white': isAttendanceRouteActive }">
              <div class="flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                ระบบลงเวลาออนไลน์
              </div>
              <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': attendanceMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>
            
            <div v-show="attendanceMenuOpen" class="mt-1 pl-6 space-y-1">
              <router-link to="/attendance/check-in" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                ลงเวลาปฏิบัติงาน
              </router-link>
              <router-link to="/attendance/history" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                ประวัติการลงเวลา
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.isHRHead" to="/admin/attendances" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                รายงานการลงเวลา
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.isHRHead" to="/admin/attendances/summary" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                สรุปผลการปฏิบัติงาน
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.isHRHead" to="/admin/ot-summary" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                สรุปผลการปฏิบัติงาน (OT)
              </router-link>
            </div>
          </div>

          <!-- ระบบสารบัญอิเล็กทรอนิกส์ Collapsible (Mobile) -->
          <div v-if="(globalSettings.enable_saraban_menu !== '0') && (authStore.isAdmin || authStore.isSarabanOfficer || authStore.hasPermission('saraban.document.view') || authStore.isManager)">
            <button @click="sarabanMenuOpen = !sarabanMenuOpen"
              class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-white/80 hover:bg-white/10"
              :class="{ 'bg-white/20 text-white': isSarabanRouteActive }">
              <div class="flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                ระบบสารบัญ
              </div>
              <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': sarabanMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>
            
            <div v-show="sarabanMenuOpen" class="mt-1 pl-6 space-y-1">
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('saraban.document.create')" to="/saraban/create" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                ลงทะเบียนหนังสือใหม่
              </router-link>
              <router-link to="/saraban" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white"
                :class="{ '!bg-white/20 !text-white': $route.path === '/saraban' }">
                ทะเบียนรับ-ส่ง (ทั้งหมด)
              </router-link>
              <router-link to="/saraban/incoming" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                หนังสือรับ
              </router-link>
              <router-link to="/saraban/outgoing" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                หนังสือส่ง
              </router-link>
              <router-link to="/saraban/circulars" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                หนังสือเวียน
              </router-link>
              <router-link to="/saraban/reports" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                รายงานสถิติ
              </router-link>

              <router-link v-if="authStore.isAdmin || authStore.isSarabanOfficer" to="/saraban/types" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                จัดการหมวดหมู่เอกสาร
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('manage_workflow_steps')" to="/saraban/workflow-templates" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                สายการอนุมัติ
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('manage_settings')" to="/saraban/settings" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                ตั้งค่าระบบสารบัญ
              </router-link>
            </div>
          </div>

          <router-link v-if="authStore.isAdmin || authStore.isHRHead || authStore.hasPermission('admin-users')" to="/admin/users" @click="mobileMenu=false"
            class="flex items-center gap-2 px-3 py-2 rounded-lg text-white/80 hover:bg-white/10"
            active-class="!bg-white/20 !text-white">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            จัดการผู้ใช้
          </router-link>
          <!-- SuperAdmin only: ตั้งค่าระบบ Collapsible (Mobile) -->
          <div v-if="authStore.hasAnySettingsMenu">
            <button @click="adminMenuOpen = !adminMenuOpen"
              class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-white/80 hover:bg-white/10"
              :class="{ 'bg-white/20 text-white': isAdminRouteActive }">
              <div class="flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                ตั้งค่าระบบ
              </div>
              <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': adminMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>
            
            <div v-show="adminMenuOpen" class="mt-1 pl-6 space-y-1">
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-settings')" to="/admin/settings" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                ข้อมูลสถานศึกษา
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-departments')" to="/admin/departments" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                จัดการแผนกวิชา
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-workflow')" to="/admin/workflow" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                จัดการลำดับการอนุมัติ
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-leave-types')" to="/admin/leave-types" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                ประเภทการลา
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-fiscal-years')" to="/admin/fiscal-years" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                ปีงบประมาณ
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('admin-notifications')" to="/admin/notifications" @click="mobileMenu=false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white">
                การแจ้งเตือน
              </router-link>
            </div>
          </div>
          <div class="text-xs text-center text-white/50 mb-1 mt-2">
            ออกอัตโนมัติใน: {{ formattedIdleTime }}
          </div>
          <button @click="confirmLogout" class="flex items-center gap-2 w-full text-left px-3 py-2 rounded-lg text-white/80 hover:bg-red-900/50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            ออกจากระบบ
          </button>
        </div>
      </transition>

      <!-- Desktop Header -->
      <header
        v-if="route.name !== 'login'"
        class="hidden md:flex bg-white shadow-sm border-b border-gray-200 px-8 py-3 justify-end items-center gap-4 z-10"
      >
        <NotificationBell />
      </header>

      <!-- Page content -->
      <main class="flex-1 p-4 md:p-8 overflow-auto">
        <router-view />
      </main>
    </div>

    <!-- Logout Confirmation Modal -->
    <transition name="fade">
      <div v-if="showLogoutModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
          <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
          </div>
          <h3 class="font-bold text-gray-900 text-lg mb-1">ออกจากระบบ?</h3>
          <p class="text-gray-500 text-sm mb-6">คุณต้องการออกจากระบบใช่หรือไม่?</p>
          <div class="flex gap-3">
            <button @click="showLogoutModal = false"
              class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors">
              ยกเลิก
            </button>
            <button @click="doLogout"
              class="flex-1 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-colors">
              ออกจากระบบ
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useLeaveStore } from './stores/leave'
import AlertList from './components/AlertList.vue'
import NotificationBell from './components/NotificationBell.vue'
import api from './axios'

const router = useRouter()
const route  = useRoute()
const authStore = useAuthStore()
const leaveStore = useLeaveStore()
const mobileMenu = ref(false)
const showLogoutModal = ref(false)
const isSidebarCollapsed = ref(false)
const globalSettings = ref({})

const leaveMenuOpen = ref(false)
const attendanceMenuOpen = ref(false)
const adminMenuOpen = ref(false)
const sarabanMenuOpen = ref(false)

const isLeaveRouteActive = computed(() => {
  return ['request', 'leaves', 'reports', 'approvals', 'cancel-approvals', 'team-calendar'].includes(route.name)
})
const isAttendanceRouteActive = computed(() => {
  return ['attendance-check-in', 'attendance-history', 'admin-attendances'].includes(route.name)
})
const isAdminRouteActive = computed(() => {
  return ['admin-settings', 'admin-departments', 'admin-workflow', 'admin-leave-types', 'admin-fiscal-years', 'admin-notifications'].includes(route.name)
})
const isSarabanRouteActive = computed(() => {
  return ['saraban-list', 'saraban-create', 'saraban-detail'].includes(route.name)
})

const IDLE_TIMEOUT_MS = 30 * 60 * 1000; // 30 minutes
const idleTimeLeft = ref(IDLE_TIMEOUT_MS);
let idleTimer = null;
let countdownInterval = null;

const resetIdleTimer = () => {
  if (route.name === 'login') return;
  idleTimeLeft.value = IDLE_TIMEOUT_MS;
  if (idleTimer) clearTimeout(idleTimer);
  idleTimer = setTimeout(() => {
    doLogout();
  }, IDLE_TIMEOUT_MS);
};

const updateCountdown = () => {
  if (route.name === 'login') return;
  idleTimeLeft.value = Math.max(0, idleTimeLeft.value - 1000);
};

const formattedIdleTime = computed(() => {
  const totalSeconds = Math.floor(idleTimeLeft.value / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${seconds.toString().padStart(2, '0')}`;
});

onMounted(async () => {
  if (authStore.token && !authStore.user) {
    await authStore.fetchUser()
  }

  try {
    const res = await api.get('/settings')
    globalSettings.value = res.data || {}
  } catch (e) {
    console.error('Failed to fetch global settings', e)
  }

  window.addEventListener('mousemove', resetIdleTimer);
  window.addEventListener('keydown', resetIdleTimer);
  window.addEventListener('mousedown', resetIdleTimer);
  window.addEventListener('touchstart', resetIdleTimer);

  resetIdleTimer();
  countdownInterval = setInterval(updateCountdown, 1000);
})

onUnmounted(() => {
  window.removeEventListener('mousemove', resetIdleTimer);
  window.removeEventListener('keydown', resetIdleTimer);
  window.removeEventListener('mousedown', resetIdleTimer);
  window.removeEventListener('touchstart', resetIdleTimer);
  if (idleTimer) clearTimeout(idleTimer);
  if (countdownInterval) clearInterval(countdownInterval);
})

// Pending badge count for approvals menu
const pendingBadge = computed(() => leaveStore.approvals?.filter(l => l.cancellation_status !== 'Pending').length || 0)
const pendingCancelBadge = computed(() => leaveStore.approvals?.filter(l => l.cancellation_status === 'Pending').length || 0)

// Role label for display
const roleLabel = computed(() => {
  const map = {
    'Super Admin': '👑 ผู้ดูแลระบบ',
    'SuperAdmin':  '👑 ผู้ดูแลระบบ',
    'Director':    '🏢 ผู้อำนวยการ',
    'Deputy Director': '👔 รองผู้อำนวยการ',
    'Deputy':      '👔 รองผู้อำนวยการ',
    'HR Head':     '📋 หัวหน้าบุคลากร',
    'HRHead':      '📋 หัวหน้าบุคลากร',
    'Employee':    '👤 พนักงาน',
  }
  return map[authStore.user?.role?.name] || authStore.roleName || '—'
})

// Role badge color
const roleBadgeClass = computed(() => {
  const map = {
    'Super Admin': 'bg-yellow-400/30 text-yellow-200',
    'SuperAdmin':  'bg-yellow-400/30 text-yellow-200',
    'Director':    'bg-blue-400/30 text-blue-200',
    'Deputy Director': 'bg-purple-400/30 text-purple-200',
    'Deputy':      'bg-purple-400/30 text-purple-200',
    'HR Head':     'bg-green-400/30 text-green-200',
    'HRHead':      'bg-green-400/30 text-green-200',
    'Employee':    'bg-white/10 text-white/70',
  }
  return map[authStore.user?.role?.name] || 'bg-white/10 text-white/70'
})

const confirmLogout = () => {
  mobileMenu.value = false
  showLogoutModal.value = true
}

const doLogout = () => {
  showLogoutModal.value = false
  authStore.logout()
  router.push({ name: 'login' })
}
</script>

<style scoped>
.nav-link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.625rem 0.75rem;
  border-radius: 0.75rem;
  color: rgba(255,255,255,0.7);
  font-size: 0.875rem;
  font-weight: 500;
  transition: background 0.15s, color 0.15s;
  text-decoration: none;
  cursor: pointer;
}
.nav-link:hover {
  background: rgba(255,255,255,0.1);
  color: #fff;
}
.nav-link-active {
  background: rgba(255,255,255,0.2) !important;
  color: #fff !important;
  font-weight: 600;
}

/* Slide down for mobile menu */
.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.2s ease;
}
.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

/* Fade for modal */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>

