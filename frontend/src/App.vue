<template>
  <div class="h-screen flex bg-gray-50 overflow-hidden font-sans">
    <!-- Skip to main content link for keyboard and screen reader accessibility -->
    <a
      href="#main-content"
      class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:bg-[#800000] focus:text-white focus:px-4 focus:py-2 focus:rounded-xl focus:shadow-xl focus:outline-none focus:ring-2 focus:ring-white text-sm font-semibold"
    >
      ข้ามไปยังเนื้อหาหลัก
    </a>

    <AlertList />

    <!-- Sidebar (desktop) -->
    <aside
      v-if="!isBareLayout"
      class="hidden md:flex md:flex-col bg-[#800000] text-white shadow-2xl flex-shrink-0 transition-all duration-300 relative z-20 h-screen"
      :class="isSidebarCollapsed ? 'w-20' : 'w-64'"
      aria-label="แถบเมนูหลักของระบบ"
    >
      <!-- Collapse Button -->
      <button 
        @click="isSidebarCollapsed = !isSidebarCollapsed" 
        class="absolute -right-3 top-8 w-6 h-6 bg-white text-[#800000] rounded-full shadow-md flex items-center justify-center hover:bg-gray-100 z-30 transition-transform focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000]"
        :class="{'rotate-180': isSidebarCollapsed}"
        :title="isSidebarCollapsed ? 'ขยายแถบเมนู' : 'ย่อแถบเมนู'"
        :aria-label="isSidebarCollapsed ? 'ขยายแถบเมนู' : 'ย่อแถบเมนู'"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
      </button>
      <!-- Logo -->
      <div class="flex items-center gap-3 py-6 border-b border-white/10 transition-all duration-300" :class="isSidebarCollapsed ? 'justify-center px-2' : 'px-6'">
        <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
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
      <router-link to="/profile" class="block py-4 border-b border-white/10 hover:bg-white/5 transition-all duration-300 cursor-pointer" :class="isSidebarCollapsed ? 'px-2' : 'px-6'" aria-label="ดูข้อมูลโปรไฟล์ส่วนตัว">
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
          <svg v-show="!isSidebarCollapsed" class="w-4 h-4 text-white/50 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </div>
      </router-link>

      <!-- Navigation -->
      <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto" aria-label="เมนูหลัก">
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

            <!-- บัตรลา -->
            <router-link
              to="/leave-card"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              บัตรลา
            </router-link>

            <!-- รายการใบลา -->
            <router-link
              to="/leaves"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              รายการใบลาของฉัน
            </router-link>

            <!-- บริการและเอกสารใบลา -->
            <router-link
              to="/leave-services"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              บริการและเอกสารใบลา
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
              v-if="authStore.isUserManager || authStore.hasPermission('leave.management.manage')"
              to="/admin/leaves-management"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              จัดการ/ซิงค์วันลา (HR)
            </router-link>

            <!-- คัดกรองเกณฑ์เลื่อนเงินเดือน -->
            <router-link
              v-if="authStore.hasAnyPermission(['leave.report.view', 'leave.management.manage'])"
              to="/admin/promotion-screening"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              คัดกรองเลื่อนเงินเดือน
            </router-link>

            <!-- ศูนย์งานกำกับการลา -->
            <router-link
              v-if="authStore.isAdmin || authStore.hasPermission('leave.management.manage')"
              to="/admin/leave-governance"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              ศูนย์งานกำกับการลา
            </router-link>
          </div>
        </div>

        <!-- ระบบลงเวลาออนไลน์ Collapsible — แสดงเฉพาะผู้ที่มีสิทธิ์ attendance module และเปิดใช้งานโมดูล -->
        <div v-if="settingStore.isAttendanceEnabled && authStore.hasAttendanceAccess">
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
              v-if="authStore.canCheckIn"
              to="/attendance/check-in"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              ลงเวลาปฏิบัติงาน
            </router-link>

            <!-- ประวัติการลงเวลา -->
            <router-link
              v-if="authStore.canViewSelfAttendance"
              to="/attendance/history"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              ประวัติการลงเวลา
            </router-link>

            <!-- รายงานการลงเวลา (Admin/HR) -->
            <router-link
              v-if="authStore.canViewAllAttendance"
              to="/admin/attendances"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              รายงานการลงเวลา
            </router-link>

            <!-- สรุปผลการปฏิบัติงาน (Admin/HR) -->
            <router-link
              v-if="authStore.canViewAllAttendance"
              to="/admin/attendances/summary"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              สรุปผลการปฏิบัติงาน
            </router-link>

            <!-- คำสั่ง OT -->
            <router-link
              v-if="authStore.hasAnyPermission(['overtime.operation.execute', 'overtime.management.review'])"
              to="/admin/overtime-orders"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              คำสั่งปฏิบัติงานล่วงเวลา (OT)
            </router-link>

            <!-- สรุปผล OT -->
            <router-link
              v-if="authStore.canManageOT"
              to="/admin/ot-summary"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              สรุปผลการปฏิบัติงาน (OT)
            </router-link>
          </div>
        </div>

        <!-- ระบบสารบรรณอิเล็กทรอนิกส์ Collapsible -->
        <div v-if="settingStore.isSarabanEnabled && (authStore.isAdmin || authStore.hasAnyPermission(['saraban.view', 'saraban.document.view', 'saraban.document.create', 'saraban.document.approve', 'saraban.document.sign', 'saraban.circular.broadcast', 'saraban.setting.manage']))">
          <button
            @click="sarabanMenuOpen = !sarabanMenuOpen; if(isSidebarCollapsed) isSidebarCollapsed = false"
            class="nav-link w-full flex justify-between items-center"
            :class="[isSarabanRouteActive ? 'bg-white/10 text-white' : '', isSidebarCollapsed ? 'justify-center px-0' : '']"
            :title="isSidebarCollapsed ? 'ระบบสารบรรณ' : ''"
          >
            <div class="flex items-center gap-3">
              <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              <span v-show="!isSidebarCollapsed" class="whitespace-nowrap">ระบบสารบรรณ</span>
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
              v-if="authStore.hasPermission('saraban.document.report')"
              to="/saraban/reports"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              รายงานสถิติ
            </router-link>

            <!-- จัดการหมวดหมู่เอกสาร -->
            <router-link
              v-if="authStore.hasPermission('saraban.document.manage_types')"
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
            <!-- ตั้งค่าระบบสารบรรณ -->
            <router-link
              v-if="authStore.hasPermission('saraban.setting.manage')"
              to="/saraban/settings"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              ตั้งค่าระบบสารบรรณ
            </router-link>
          </div>
        </div>


        <!-- Divider for admin section -->
        <div v-if="authStore.hasAnyAdminMenu" class="pt-3 pb-1" :class="isSidebarCollapsed ? 'text-center' : ''">
          <p class="text-white/30 text-[10px] uppercase tracking-widest px-3" v-if="!isSidebarCollapsed">ผู้ดูแลระบบ</p>
          <div v-else class="h-px bg-white/20 mx-4 my-2"></div>
        </div>

        <!-- SuperAdmin & HR: จัดการผู้ใช้งาน Collapsible -->
        <div v-if="authStore.isAdmin || authStore.isHRHead || authStore.hasAnyPermission(['view_users', 'edit_users', 'system.user.view', 'system.user.manage'])">
          <button
            @click="userMenuOpen = !userMenuOpen; if(isSidebarCollapsed) isSidebarCollapsed = false"
            class="nav-link w-full flex justify-between items-center"
            :class="[isUserRouteActive ? 'bg-white/10 text-white' : '', isSidebarCollapsed ? 'justify-center px-0' : '']"
            :title="isSidebarCollapsed ? 'จัดการผู้ใช้งาน' : ''"
          >
            <div class="flex items-center gap-3">
              <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
              <span v-show="!isSidebarCollapsed" class="whitespace-nowrap">จัดการผู้ใช้งาน</span>
            </div>
            <svg v-show="!isSidebarCollapsed" class="w-4 h-4 transition-transform duration-200 flex-shrink-0" :class="{ 'rotate-180': userMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>

          <div v-show="userMenuOpen && !isSidebarCollapsed" class="mt-1 space-y-1 pl-9">
            <router-link v-if="authStore.isAdmin || authStore.hasAnyPermission(['view_users', 'system.user.view'])" to="/admin/users" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              รายชื่อผู้ใช้งาน
            </router-link>
            <router-link
              v-if="authStore.isAdmin || authStore.hasAnyPermission(['edit_users', 'system.user.manage'])"
              to="/admin/personnel-assignments"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              กำหนดประเภทบุคลากร
            </router-link>
            <!--
              ซ่อนเมนู "จัดการเมนูตามสิทธิ์" ไว้ก่อน: แถบเมนูข้างชุดนี้เขียนตายตัวใน App.vue
              ยังไม่ได้อ่านจาก /user/menus จริง การตั้งค่าในหน้านั้นจึงไม่มีผลกับสิ่งที่ผู้ใช้เห็น
              route /admin/menus และ API ยังคงอยู่ หากทำ dynamic menu สำเร็จให้เปิดเมนูนี้กลับมา
            <router-link
              v-if="authStore.isAdmin || authStore.hasPermission('system.menu.manage')"
              to="/admin/menus"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              จัดการเมนูตามสิทธิ์
            </router-link>
            -->
            <router-link
              v-if="authStore.isAdmin || authStore.hasPermission('system.role.manage')"
              to="/admin/roles"
              class="nav-link !py-2 !text-sm"
              active-class="nav-link-active"
            >
              จัดการบทบาทและสิทธิ์
            </router-link>
          </div>
        </div>

        <!-- SuperAdmin & Managers: ตั้งค่าระบบ Collapsible -->
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
            <p class="settings-group-label">ทั่วไป</p>
            <router-link v-if="authStore.canManageSettings" to="/admin/settings" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              ข้อมูลสถานศึกษาและแบบฟอร์ม
            </router-link>
            <router-link v-if="authStore.canManageSettings" to="/admin/fiscal-years" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              ปีงบประมาณ
            </router-link>
            <router-link v-if="authStore.canManageSettings" to="/admin/work-calendar" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              ปฏิทินวันทำการ
            </router-link>
            <router-link v-if="authStore.isAdmin || authStore.hasPermission('manage_departments')" to="/admin/departments" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              จัดการแผนกวิชา
            </router-link>

            <p class="settings-group-label">ระบบลา</p>
            <router-link v-if="authStore.canManageSettings" to="/admin/leave-policies" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              นโยบายสิทธิการลา
            </router-link>
            <router-link v-if="authStore.isAdmin || authStore.hasPermission('manage_leave_types')" to="/admin/leave-types" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              ประเภทการลา
            </router-link>

            <template v-if="settingStore.isAttendanceEnabled && authStore.canManageSettings">
              <p class="settings-group-label">ระบบลงเวลา</p>
              <router-link to="/admin/attendance-settings" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
                ตั้งค่าระบบลงเวลา
              </router-link>
            </template>

            <p class="settings-group-label">ระบบ</p>
            <router-link v-if="authStore.isAdmin || authStore.hasPermission('manage_workflow_steps')" to="/admin/workflow" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              จัดการลำดับการอนุมัติ
            </router-link>
            <router-link v-if="authStore.canManageSettings" to="/admin/notifications" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              การแจ้งเตือน
            </router-link>
            <router-link v-if="authStore.canManageSettings" to="/admin/document-templates" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              จัดการแม่แบบเอกสาร
            </router-link>
            <router-link v-if="authStore.canManageSettings" to="/admin/modules" class="nav-link !py-2 !text-sm" active-class="nav-link-active">
              โมดูลระบบ
            </router-link>
          </div>
        </div>
      </nav>

      <!-- Desktop Sidebar Footer / Logout -->
      <div class="px-4 py-4 border-t border-white/10 flex-shrink-0">
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

    <!-- Mobile Drawer Overlay & Panel -->
    <transition name="drawer-fade">
      <div
        v-if="mobileMenu && !isBareLayout"
        class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 md:hidden"
        @click="mobileMenu = false"
      ></div>
    </transition>

    <transition name="drawer-slide">
      <div
        v-if="mobileMenu && !isBareLayout"
        role="dialog"
        aria-modal="true"
        aria-label="เมนูนำทางบนอุปกรณ์มือถือ"
        @keydown.escape="mobileMenu = false"
        tabindex="-1"
        class="fixed inset-y-0 left-0 w-72 max-w-[85vw] bg-[#800000] text-white shadow-2xl z-50 md:hidden flex flex-col h-full overflow-hidden focus:outline-none"
      >
        <!-- Mobile Drawer Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-white/10 flex-shrink-0">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
            </div>
            <div>
              <h1 class="font-bold text-base leading-tight">E-Leave</h1>
              <p class="text-white/60 text-xs">ระบบลางานออนไลน์</p>
            </div>
          </div>
          <button @click="mobileMenu = false" class="p-1.5 rounded-lg text-white/80 hover:bg-white/10 hover:text-white transition-colors focus-visible:ring-2 focus-visible:ring-white focus:outline-none" aria-label="ปิดเมนู">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Mobile Drawer User Profile -->
        <router-link to="/profile" @click="mobileMenu = false" class="block px-5 py-3.5 border-b border-white/10 hover:bg-white/5 transition-colors cursor-pointer flex-shrink-0" aria-label="ดูข้อมูลโปรไฟล์ส่วนตัว">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-white/20 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0">
              {{ authStore.user?.name?.charAt(0)?.toUpperCase() }}
            </div>
            <div class="min-w-0 flex-1">
              <p class="font-semibold text-sm truncate text-white">{{ authStore.user?.name }}</p>
              <span class="inline-block text-xs px-2 py-0.5 rounded-full mt-0.5 truncate max-w-full" :class="roleBadgeClass">
                {{ roleLabel }}
              </span>
            </div>
          </div>
        </router-link>

        <!-- Mobile Drawer Scrollable Nav -->
        <nav class="flex-1 px-3 py-3 space-y-1 overflow-y-auto" aria-label="เมนูหลักบนมือถือ">
          <!-- หน้าหลัก -->
          <router-link
            to="/"
            @click="mobileMenu = false"
            class="flex items-center gap-3 px-3 py-2 rounded-xl text-white/80 hover:bg-white/10"
            active-class="!bg-white/20 !text-white font-semibold"
          >
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            <span>หน้าหลัก</span>
          </router-link>

          <!-- ระบบลางาน Collapsible -->
          <div>
            <button
              @click="leaveMenuOpen = !leaveMenuOpen"
              class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-white/80 hover:bg-white/10"
              :class="{ 'bg-white/15 text-white font-semibold': isLeaveRouteActive }"
            >
              <div class="flex items-center gap-3">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <span>ระบบลางาน</span>
              </div>
              <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': leaveMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>
            
            <div v-show="leaveMenuOpen" class="mt-1 pl-6 space-y-1">
              <router-link to="/request" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ยื่นใบลา
              </router-link>
              <router-link to="/reports" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                รายงานสถิติการลา
              </router-link>
              <router-link to="/leave-card" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                บัตรลา
              </router-link>
              <router-link to="/leaves" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                รายการใบลาของฉัน
              </router-link>
              <router-link to="/leave-services" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                บริการและเอกสารใบลา
              </router-link>
              <router-link to="/team-calendar" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ปฏิทินวันลาทีม
              </router-link>
              <router-link v-if="authStore.isManager || authStore.hasPermission('approvals')" to="/approvals" @click="mobileMenu = false" class="flex items-center justify-between px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                <span>อนุมัติใบลา</span>
                <span v-if="pendingBadge > 0" class="bg-yellow-400 text-yellow-900 text-xs font-bold px-1.5 py-0.5 rounded-full">{{ pendingBadge }}</span>
              </router-link>
              <router-link v-if="authStore.isManager || authStore.hasPermission('cancel-approvals')" to="/cancel-approvals" @click="mobileMenu = false" class="flex items-center justify-between px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                <span>อนุมัติยกเลิกใบลา</span>
                <span v-if="pendingCancelBadge > 0" class="bg-orange-400 text-orange-900 text-xs font-bold px-1.5 py-0.5 rounded-full">{{ pendingCancelBadge }}</span>
              </router-link>
              <router-link v-if="authStore.isUserManager || authStore.hasPermission('leave.management.manage')" to="/admin/leaves-management" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                จัดการ/ซิงค์วันลา (HR)
              </router-link>
              <router-link v-if="authStore.hasAnyPermission(['leave.report.view', 'leave.management.manage'])" to="/admin/promotion-screening" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                คัดกรองเลื่อนเงินเดือน
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('leave.management.manage')" to="/admin/leave-governance" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ศูนย์งานกำกับการลา
              </router-link>
            </div>
          </div>

          <!-- ระบบลงเวลาออนไลน์ Collapsible (Mobile) -->
          <div v-if="settingStore.isAttendanceEnabled && authStore.hasAttendanceAccess">
            <button
              @click="attendanceMenuOpen = !attendanceMenuOpen"
              class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-white/80 hover:bg-white/10"
              :class="{ 'bg-white/15 text-white font-semibold': isAttendanceRouteActive }"
            >
              <div class="flex items-center gap-3">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>ระบบลงเวลาออนไลน์</span>
              </div>
              <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': attendanceMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>

            <div v-show="attendanceMenuOpen" class="mt-1 pl-6 space-y-1">
              <router-link v-if="authStore.canCheckIn" to="/attendance/check-in" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ลงเวลาปฏิบัติงาน
              </router-link>
              <router-link v-if="authStore.canViewSelfAttendance" to="/attendance/history" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ประวัติการลงเวลา
              </router-link>
              <router-link v-if="authStore.canViewAllAttendance" to="/admin/attendances" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                รายงานการลงเวลา
              </router-link>
              <router-link v-if="authStore.canViewAllAttendance" to="/admin/attendances/summary" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                สรุปผลการปฏิบัติงาน
              </router-link>
              <router-link v-if="authStore.hasAnyPermission(['overtime.operation.execute', 'overtime.management.review'])" to="/admin/overtime-orders" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                คำสั่งปฏิบัติงานล่วงเวลา (OT)
              </router-link>
              <router-link v-if="authStore.canManageOT" to="/admin/ot-summary" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                สรุปผลการปฏิบัติงาน (OT)
              </router-link>
            </div>
          </div>

          <!-- ระบบสารบรรณ Collapsible (Mobile) -->
          <div v-if="settingStore.isSarabanEnabled && (authStore.isAdmin || authStore.hasAnyPermission(['saraban.view', 'saraban.document.view', 'saraban.document.create', 'saraban.document.approve', 'saraban.document.sign', 'saraban.circular.broadcast', 'saraban.setting.manage']))">
            <button
              @click="sarabanMenuOpen = !sarabanMenuOpen"
              class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-white/80 hover:bg-white/10"
              :class="{ 'bg-white/15 text-white font-semibold': isSarabanRouteActive }"
            >
              <div class="flex items-center gap-3">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>ระบบสารบรรณ</span>
              </div>
              <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': sarabanMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>
            
            <div v-show="sarabanMenuOpen" class="mt-1 pl-6 space-y-1">
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('saraban.document.create')" to="/saraban/create" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ลงทะเบียนหนังสือใหม่
              </router-link>
              <router-link to="/saraban" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold" :class="{ '!bg-white/20 !text-white font-semibold': $route.path === '/saraban' }">
                ทะเบียนรับ-ส่ง (ทั้งหมด)
              </router-link>
              <router-link to="/saraban/incoming" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                หนังสือรับ
              </router-link>
              <router-link to="/saraban/outgoing" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                หนังสือส่ง
              </router-link>
              <router-link to="/saraban/circulars" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                หนังสือเวียน
              </router-link>
              <router-link v-if="authStore.hasPermission('saraban.document.report')" to="/saraban/reports" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                รายงานสถิติ
              </router-link>
              <router-link v-if="authStore.hasPermission('saraban.document.manage_types')" to="/saraban/types" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                จัดการหมวดหมู่เอกสาร
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('manage_workflow_steps')" to="/saraban/workflow-templates" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                สายการอนุมัติ
              </router-link>
              <router-link v-if="authStore.hasPermission('saraban.setting.manage')" to="/saraban/settings" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ตั้งค่าระบบสารบรรณ
              </router-link>
            </div>
          </div>

          <!-- Divider for Mobile Admin Section -->
          <div v-if="authStore.hasAnyAdminMenu" class="pt-3 pb-1">
            <p class="text-white/30 text-[10px] uppercase tracking-widest px-3">ผู้ดูแลระบบ</p>
          </div>

          <!-- จัดการผู้ใช้งาน Collapsible (Mobile) -->
          <div v-if="authStore.isAdmin || authStore.isHRHead || authStore.hasAnyPermission(['view_users', 'edit_users', 'system.user.view', 'system.user.manage'])">
            <button
              @click="userMenuOpen = !userMenuOpen"
              class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-white/80 hover:bg-white/10"
              :class="{ 'bg-white/15 text-white font-semibold': isUserRouteActive }"
            >
              <div class="flex items-center gap-3">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span>จัดการผู้ใช้งาน</span>
              </div>
              <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': userMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>

            <div v-show="userMenuOpen" class="mt-1 pl-6 space-y-1">
              <router-link v-if="authStore.isAdmin || authStore.hasAnyPermission(['view_users', 'system.user.view'])" to="/admin/users" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                รายชื่อผู้ใช้งาน
              </router-link>
              <router-link
                v-if="authStore.isAdmin || authStore.hasAnyPermission(['edit_users', 'system.user.manage'])"
                to="/admin/personnel-assignments"
                @click="mobileMenu = false"
                class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10"
                active-class="!bg-white/20 !text-white font-semibold"
              >
                กำหนดประเภทบุคลากร
              </router-link>
              <!-- ซ่อน "จัดการเมนูตามสิทธิ์" ไว้ก่อน ดูเหตุผลประกอบที่เมนูฝั่ง desktop -->
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('system.role.manage')" to="/admin/roles" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                จัดการบทบาทและสิทธิ์
              </router-link>
            </div>
          </div>

          <!-- ตั้งค่าระบบ Collapsible (Mobile) -->
          <div v-if="authStore.hasAnySettingsMenu">
            <button
              @click="adminMenuOpen = !adminMenuOpen"
              class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-white/80 hover:bg-white/10"
              :class="{ 'bg-white/15 text-white font-semibold': isAdminRouteActive }"
            >
              <div class="flex items-center gap-3">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span>ตั้งค่าระบบ</span>
              </div>
              <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': adminMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>
            
            <div v-show="adminMenuOpen" class="mt-1 pl-6 space-y-1">
              <p class="settings-group-label">ทั่วไป</p>
              <router-link v-if="authStore.canManageSettings" to="/admin/settings" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ข้อมูลสถานศึกษาและแบบฟอร์ม
              </router-link>
              <router-link v-if="authStore.canManageSettings" to="/admin/fiscal-years" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ปีงบประมาณ
              </router-link>
              <router-link v-if="authStore.canManageSettings" to="/admin/work-calendar" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ปฏิทินวันทำการ
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('manage_departments')" to="/admin/departments" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                จัดการแผนกวิชา
              </router-link>

              <p class="settings-group-label">ระบบลา</p>
              <router-link v-if="authStore.canManageSettings" to="/admin/leave-policies" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                นโยบายสิทธิการลา
              </router-link>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('manage_leave_types')" to="/admin/leave-types" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                ประเภทการลา
              </router-link>

              <template v-if="settingStore.isAttendanceEnabled && authStore.canManageSettings">
                <p class="settings-group-label">ระบบลงเวลา</p>
                <router-link to="/admin/attendance-settings" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                  ตั้งค่าระบบลงเวลา
                </router-link>
              </template>

              <p class="settings-group-label">ระบบ</p>
              <router-link v-if="authStore.isAdmin || authStore.hasPermission('manage_workflow_steps')" to="/admin/workflow" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                จัดการลำดับการอนุมัติ
              </router-link>
              <router-link v-if="authStore.canManageSettings" to="/admin/notifications" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                การแจ้งเตือน
              </router-link>
              <router-link v-if="authStore.canManageSettings" to="/admin/document-templates" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                จัดการแม่แบบเอกสาร
              </router-link>
              <router-link v-if="authStore.canManageSettings" to="/admin/modules" @click="mobileMenu = false" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/10" active-class="!bg-white/20 !text-white font-semibold">
                โมดูลระบบ
              </router-link>
            </div>
          </div>
        </nav>

        <!-- Mobile Drawer Footer / Logout -->
        <div class="px-4 py-3 border-t border-white/10 bg-[#700000] flex-shrink-0">
          <div class="text-xs text-center text-white/50 mb-1.5 truncate">
            ออกอัตโนมัติใน: {{ formattedIdleTime }}
          </div>
          <button
            @click="confirmLogout"
            class="flex items-center justify-center gap-2 w-full px-3 py-2 rounded-xl text-white/90 hover:bg-red-900/60 hover:text-white transition-colors font-medium text-sm"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            ออกจากระบบ
          </button>
        </div>
      </div>
    </transition>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col h-screen min-w-0 overflow-hidden">
      <!-- Mobile Top Header Bar -->
      <header
        v-if="!isBareLayout"
        class="md:hidden bg-[#800000] text-white px-4 py-3 flex justify-between items-center shadow-md z-30 flex-shrink-0 sticky top-0"
      >
        <div class="flex items-center gap-3">
          <button
            @click="mobileMenu = true"
            class="p-1.5 rounded-lg hover:bg-white/15 transition-colors focus:outline-none"
            aria-label="เปิดเมนูนำทาง"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
          </button>
          <span class="font-bold text-lg tracking-tight">E-Leave</span>
        </div>
        <div class="flex items-center gap-2">
          <div class="bg-white/10 rounded-full">
            <NotificationBell />
          </div>
          <router-link to="/profile" class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center font-bold text-xs">
            {{ authStore.user?.name?.charAt(0)?.toUpperCase() }}
          </router-link>
        </div>
      </header>

      <!-- Desktop Header with Breadcrumb -->
      <header
        v-if="!isBareLayout"
        class="hidden md:flex bg-white shadow-sm border-b border-gray-200 px-6 lg:px-8 py-3.5 justify-between items-center z-10 flex-shrink-0"
      >
        <!-- Breadcrumb Navigation -->
        <nav class="flex items-center gap-2 text-sm text-gray-500 font-medium" aria-label="แถบนำทางตำแหน่ง">
          <router-link to="/" class="hover:text-[#800000] flex items-center gap-1.5 transition-colors">
            <svg class="w-4 h-4 text-[#800000]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            <span>หน้าหลัก</span>
          </router-link>
          <template v-if="route.path !== '/'">
            <span class="text-gray-300">/</span>
            <span v-if="route.meta?.parent" class="text-gray-500">{{ route.meta.parent }}</span>
            <span v-if="route.meta?.parent" class="text-gray-300">/</span>
            <span class="text-gray-900 font-semibold truncate max-w-[300px]">{{ currentRouteTitle }}</span>
          </template>
        </nav>

        <div class="flex items-center gap-4">
          <NotificationBell />
          <div class="h-5 w-px bg-gray-200"></div>
          <router-link to="/profile" class="flex items-center gap-2 text-sm text-gray-700 hover:text-gray-900">
            <div class="w-8 h-8 bg-[#800000]/10 text-[#800000] font-bold rounded-full flex items-center justify-center text-xs">
              {{ authStore.user?.name?.charAt(0)?.toUpperCase() }}
            </div>
            <span class="font-medium truncate max-w-[140px]">{{ authStore.user?.name }}</span>
          </router-link>
        </div>
      </header>

      <!-- Page Content (Independent Scrolling Container) -->
      <main class="flex-1 p-4 md:p-6 lg:p-8 overflow-y-auto focus:outline-none" id="main-content" tabindex="-1">
        <router-view />
      </main>
    </div>

    <!-- Logout Confirmation Modal -->
    <transition name="fade">
      <div
        v-if="showLogoutModal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="logout-modal-title"
        @keydown.escape="showLogoutModal = false"
        tabindex="-1"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 focus:outline-none"
      >
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
          <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
          </div>
          <h3 id="logout-modal-title" class="font-bold text-gray-900 text-lg mb-1">ออกจากระบบ?</h3>
          <p class="text-gray-500 text-sm mb-6">คุณต้องการออกจากระบบใช่หรือไม่?</p>
          <div class="flex gap-3">
            <button @click="showLogoutModal = false"
              class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors focus-visible:ring-2 focus-visible:ring-gray-400 focus:outline-none">
              ยกเลิก
            </button>
            <button @click="doLogout"
              class="flex-1 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-colors focus-visible:ring-2 focus-visible:ring-[#800000] focus:outline-none">
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
import { useSettingStore } from './stores/settingStore'
import AlertList from './components/AlertList.vue'
import NotificationBell from './components/NotificationBell.vue'
import api from './axios'

const router = useRouter()
const route  = useRoute()
// หน้าที่ไม่ต้องมีเมนู/แถบบน และไม่ต้องนับเวลาหมดอายุการใช้งาน (หน้าล็อกอิน, กู้รหัสผ่าน, และหน้าตรวจสอบเอกสารสาธารณะ)
const isBareLayout = computed(() => {
  if (route.meta?.requiresGuest || route.meta?.bare) return true
  return ['login', 'forgot-password', 'reset-password', 'verify-leave'].includes(route.name)
})

const authStore = useAuthStore()
const leaveStore = useLeaveStore()
const settingStore = useSettingStore()
const mobileMenu = ref(false)
const showLogoutModal = ref(false)
const isSidebarCollapsed = ref(false)

const leaveMenuOpen = ref(false)
const attendanceMenuOpen = ref(false)
const adminMenuOpen = ref(false)
const sarabanMenuOpen = ref(false)
const userMenuOpen = ref(false)

const currentRouteTitle = computed(() => {
  if (route.meta?.title) return route.meta.title
  return 'ระบบบริหารสถานศึกษา E-la'
})

const isLeaveRouteActive = computed(() => {
  return ['request', 'leaves', 'my-leaves', 'reports', 'approvals', 'cancel-approvals', 'team-calendar', 'leave-card', 'admin-leaves-management', 'admin-promotion-screening', 'leave-services', 'admin-leave-governance'].includes(route.name) ||
    ['/request', '/leaves', '/reports', '/leave-card', '/team-calendar', '/approvals', '/cancel-approvals', '/leave-services'].includes(route.path) ||
    route.path.startsWith('/admin/leaves-management') ||
    route.path.startsWith('/admin/promotion-screening') ||
    route.path.startsWith('/admin/leave-governance')
})

const isAttendanceRouteActive = computed(() => {
  return ['attendance-check-in', 'attendance-history', 'admin-attendances', 'admin-attendances-summary', 'admin-ot-summary', 'admin-overtime-orders'].includes(route.name) ||
    route.path.startsWith('/attendance') ||
    route.path.startsWith('/admin/attendance') ||
    route.path === '/admin/ot-summary' ||
    route.path === '/admin/overtime-orders'
})

const isAdminRouteActive = computed(() => {
  return ['admin-settings', 'admin-attendance-settings', 'admin-document-templates', 'admin-modules', 'admin-departments', 'admin-workflow', 'admin-leave-types', 'admin-fiscal-years', 'admin-notifications', 'admin-work-calendar', 'admin-leave-policies'].includes(route.name) ||
    ['/admin/settings', '/admin/attendance-settings', '/admin/document-templates', '/admin/modules', '/admin/departments', '/admin/workflow', '/admin/leave-types', '/admin/fiscal-years', '/admin/notifications', '/admin/work-calendar', '/admin/leave-policies'].includes(route.path)
})

const isSarabanRouteActive = computed(() => {
  return route.path.startsWith('/saraban')
})

const isUserRouteActive = computed(() => {
  return ['admin-users', 'admin-menus', 'admin-roles', 'admin-personnel-assignments'].includes(route.name) ||
    ['/admin/users', '/admin/menus', '/admin/roles', '/admin/personnel-assignments'].includes(route.path)
})

// Auto open active collapsible menu on navigation & close mobile drawer
watch(() => route.path, () => {
  mobileMenu.value = false
  if (isLeaveRouteActive.value) leaveMenuOpen.value = true
  if (isAttendanceRouteActive.value) attendanceMenuOpen.value = true
  if (isSarabanRouteActive.value) sarabanMenuOpen.value = true
  if (isUserRouteActive.value) userMenuOpen.value = true
  if (isAdminRouteActive.value) adminMenuOpen.value = true
}, { immediate: true })

const IDLE_TIMEOUT_MS = 30 * 60 * 1000; // 30 minutes
const idleTimeLeft = ref(IDLE_TIMEOUT_MS);
let idleTimer = null;
let countdownInterval = null;

const resetIdleTimer = () => {
  if (isBareLayout.value) return;
  idleTimeLeft.value = IDLE_TIMEOUT_MS;
  if (idleTimer) clearTimeout(idleTimer);
  idleTimer = setTimeout(() => {
    doLogout();
  }, IDLE_TIMEOUT_MS);
};

const updateCountdown = () => {
  if (isBareLayout.value) return;
  idleTimeLeft.value = Math.max(0, idleTimeLeft.value - 1000);
};

const formattedIdleTime = computed(() => {
  const totalSeconds = Math.floor(idleTimeLeft.value / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${seconds.toString().padStart(2, '0')}`;
});

onMounted(async () => {
  await settingStore.fetchSettings();

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
  const role = authStore.user?.role?.name || authStore.roleName || ''
  if (role) return role
  return 'บุคลากร'
})

// Role badge color
const roleBadgeClass = computed(() => {
  const map = {
    'ผู้ดูแลระบบสูงสุด': 'bg-yellow-400/30 text-yellow-200',
    'Super Admin': 'bg-yellow-400/30 text-yellow-200',
    'SuperAdmin':  'bg-yellow-400/30 text-yellow-200',
    'ผู้อำนวยการ': 'bg-blue-400/30 text-blue-200',
    'Director':    'bg-blue-400/30 text-blue-200',
    'รองผู้อำนวยการ': 'bg-purple-400/30 text-purple-200',
    'Deputy Director': 'bg-purple-400/30 text-purple-200',
    'Deputy':      'bg-purple-400/30 text-purple-200',
    'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย': 'bg-teal-400/30 text-teal-200',
    'เจ้าหน้าที่บุคลากร (HR)': 'bg-green-400/30 text-green-200',
    'HR Head':     'bg-green-400/30 text-green-200',
    'HRHead':      'bg-green-400/30 text-green-200',
    'เจ้าหน้าที่สารบรรณกลาง': 'bg-amber-400/30 text-amber-200',
    'บุคลากร / ครู / เจ้าหน้าที่': 'bg-white/10 text-white/70',
    'Employee':    'bg-white/10 text-white/70',
  }
  return map[authStore.user?.role?.name] || map[authStore.roleName] || 'bg-white/10 text-white/70'
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

/* หัวข้อคั่นกลุ่มย่อยในเมนูตั้งค่าระบบ */
.settings-group-label {
  padding: 0.5rem 0.75rem 0.125rem;
  font-size: 0.625rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.4);
}

/* Drawer transitions */
.drawer-fade-enter-active,
.drawer-fade-leave-active {
  transition: opacity 0.25s ease;
}
.drawer-fade-enter-from,
.drawer-fade-leave-to {
  opacity: 0;
}

.drawer-slide-enter-active,
.drawer-slide-leave-active {
  transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.drawer-slide-enter-from,
.drawer-slide-leave-to {
  transform: translateX(-100%);
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


