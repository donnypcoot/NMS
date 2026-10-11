import React, { useCallback, useEffect, useState } from 'react';
import {
  Users, ShieldCheck, Plus, Pencil, Power, RefreshCw,
  Search, Save, X, KeyRound, Lock
} from 'lucide-react';
import { api, ManagedPermission, ManagedRole } from '../services/api';
import { User } from '../types/nms';

type Tab = 'users' | 'roles';

const inputClass =
  'w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 outline-none focus:border-cyan-500';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-300';
const buttonClass =
  'inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50';

const errorMessage = (e: unknown) =>
  e instanceof Error ? e.message : 'Terjadi kesalahan yang tidak diketahui.';

export const UsersPage: React.FC<{ permissions: string[] }> = ({ permissions }) => {
  const canUsers = permissions.includes('users.manage');
  const canRoles = permissions.includes('roles.manage');
  const [tab, setTab] = useState<Tab>(canUsers ? 'users' : 'roles');
  const [users, setUsers] = useState<User[]>([]);
  const [roles, setRoles] = useState<ManagedRole[]>([]);
  const [permissionList, setPermissionList] = useState<ManagedPermission[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [search, setSearch] = useState('');

  const [userEditor, setUserEditor] = useState(false);
  const [editingUser, setEditingUser] = useState<User | null>(null);
  const [userForm, setUserForm] = useState({
    username: '', email: '', full_name: '', password: '',
    role_id: 0, is_active: 1
  });

  const [roleEditor, setRoleEditor] = useState(false);
  const [editingRole, setEditingRole] = useState<ManagedRole | null>(null);
  const [roleForm, setRoleForm] = useState({
    name: '', description: '', permissions: [] as number[]
  });

  const loadData = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const tasks: Promise<unknown>[] = [];
      let userTask: ReturnType<typeof api.getUsers> | null = null;
      let roleTask: ReturnType<typeof api.getRoles> | null = null;

      if (canUsers) {
        userTask = api.getUsers();
        tasks.push(userTask);
      }
      if (canRoles || canUsers) {
        roleTask = api.getRoles();
        tasks.push(roleTask);
      }

      const results = await Promise.all(tasks);
      let index = 0;

      if (userTask) {
        const result = results[index++] as Awaited<typeof userTask>;
        setUsers(result.users || []);
      }
      if (roleTask) {
        const result = results[index] as Awaited<typeof roleTask>;
        setRoles(result.roles || []);
        setPermissionList(result.permissions || []);
      }
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [canUsers, canRoles]);

  useEffect(() => {
    if (!canUsers && !canRoles) {
      setLoading(false);
      setError('Akun Anda tidak memiliki izin untuk mengelola pengguna atau role.');
      return;
    }
    if (!canUsers && canRoles) setTab('roles');
    loadData();
  }, [canUsers, canRoles, loadData]);

  const openCreateUser = () => {
    setEditingUser(null);
    setUserForm({
      username: '', email: '', full_name: '', password: '',
      role_id: roles[0]?.id || 0, is_active: 1
    });
    setError('');
    setNotice('');
    setUserEditor(true);
  };

  const openEditUser = (user: User) => {
    setEditingUser(user);
    setUserForm({
      username: user.username,
      email: user.email,
      full_name: user.full_name,
      password: '',
      role_id: user.role_id,
      is_active: Number(user.is_active)
    });
    setError('');
    setNotice('');
    setUserEditor(true);
  };

  const saveUser = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setNotice('');
    try {
      if (editingUser) {
        const payload: {
          username: string; email: string; full_name: string;
          role_id: number; is_active: number; password?: string
        } = {
          username: userForm.username.trim(),
          email: userForm.email.trim(),
          full_name: userForm.full_name.trim(),
          role_id: Number(userForm.role_id),
          is_active: Number(userForm.is_active)
        };
        if (userForm.password) payload.password = userForm.password;
        await api.updateUser(editingUser.id, payload);
      } else {
        await api.createUser({
          username: userForm.username.trim(),
          email: userForm.email.trim(),
          full_name: userForm.full_name.trim(),
          password: userForm.password,
          role_id: Number(userForm.role_id),
          is_active: Number(userForm.is_active)
        });
      }
      setUserEditor(false);
      setNotice(editingUser ? 'Akun pengguna berhasil diperbarui.' : 'Akun pengguna berhasil dibuat.');
      await loadData();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  const deactivateUser = async (user: User) => {
    if (!window.confirm(`Nonaktifkan akun "${user.username}"? Akun tidak akan dihapus dari database.`)) return;
    setError('');
    setNotice('');
    try {
      await api.deactivateUser(user.id);
      setNotice(`Akun ${user.username} berhasil dinonaktifkan.`);
      await loadData();
    } catch (e) {
      setError(errorMessage(e));
    }
  };

  const openCreateRole = () => {
    setEditingRole(null);
    setRoleForm({ name: '', description: '', permissions: [] });
    setError('');
    setNotice('');
    setRoleEditor(true);
  };

  const openEditRole = (role: ManagedRole) => {
    setEditingRole(role);
    setRoleForm({
      name: role.name,
      description: role.description || '',
      permissions: role.permissions || []
    });
    setError('');
    setNotice('');
    setRoleEditor(true);
  };

  const saveRole = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const payload = {
        name: roleForm.name.trim(),
        description: roleForm.description.trim(),
        permissions: roleForm.permissions
      };
      if (editingRole) {
        await api.updateRole(editingRole.id, payload);
      } else {
        await api.createRole(payload);
      }
      setRoleEditor(false);
      setNotice(editingRole ? 'Role berhasil diperbarui.' : 'Role berhasil dibuat.');
      await loadData();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  const deleteRole = async (role: ManagedRole) => {
    if (!window.confirm(`Hapus role "${role.name}"? Tindakan ini hanya dapat dilakukan jika role tidak digunakan.`)) return;
    setError('');
    setNotice('');
    try {
      await api.deleteRole(role.id);
      setNotice(`Role ${role.name} berhasil dihapus.`);
      await loadData();
    } catch (e) {
      setError(errorMessage(e));
    }
  };

  const visibleUsers = users.filter((user) =>
    [user.username, user.full_name, user.email, user.role_name]
      .some((value) => (value || '').toLowerCase().includes(search.toLowerCase()))
  );

  const togglePermission = (id: number, checked: boolean) => {
    setRoleForm((current) => ({
      ...current,
      permissions: checked
        ? [...new Set([...current.permissions, id])]
        : current.permissions.filter((permissionId) => permissionId !== id)
    }));
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold text-white">Manajemen User &amp; Role</h1>
          <p className="mt-1 text-sm text-slate-400">
            Kelola akun pengguna dan hak akses operasional NMS.
          </p>
        </div>
        <button
          type="button"
          onClick={loadData}
          disabled={loading}
          className={`${buttonClass} border border-slate-700 bg-slate-900 text-slate-300 hover:bg-slate-800`}
        >
          <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
          Muat ulang
        </button>
      </div>

      <div className="flex gap-2 border-b border-slate-800">
        {canUsers && (
          <button
            type="button"
            onClick={() => { setTab('users'); setError(''); setNotice(''); }}
            className={`inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm ${
              tab === 'users' ? 'border-cyan-400 text-cyan-300' : 'border-transparent text-slate-400 hover:text-white'
            }`}
          >
            <Users className="h-4 w-4" /> Pengguna
          </button>
        )}
        {canRoles && (
          <button
            type="button"
            onClick={() => { setTab('roles'); setError(''); setNotice(''); }}
            className={`inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm ${
              tab === 'roles' ? 'border-cyan-400 text-cyan-300' : 'border-transparent text-slate-400 hover:text-white'
            }`}
          >
            <ShieldCheck className="h-4 w-4" /> Role &amp; Permission
          </button>
        )}
      </div>

      {error && (
        <div role="alert" className="rounded-lg border border-rose-800/70 bg-rose-950/40 px-4 py-3 text-sm text-rose-200">
          {error}
          <button type="button" onClick={() => setError('')} className="ml-3 underline">Tutup</button>
        </div>
      )}
      {notice && (
        <div role="status" className="rounded-lg border border-emerald-800/70 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-200">
          {notice}
        </div>
      )}

      {loading ? (
        <div className="flex items-center justify-center gap-2 rounded-xl border border-slate-800 bg-slate-900/50 p-12 text-sm text-slate-400">
          <RefreshCw className="h-4 w-4 animate-spin" /> Mengambil data dari server...
        </div>
      ) : tab === 'users' && canUsers ? (
        <section className="overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
          <div className="flex flex-col gap-3 border-b border-slate-800 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="font-semibold text-white">Daftar pengguna</h2>
              <p className="mt-1 text-xs text-slate-400">{users.length} akun terdaftar</p>
            </div>
            <div className="flex flex-col gap-2 sm:flex-row">
              <div className="relative">
                <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-500" />
                <input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Cari pengguna..."
                  className={`${inputClass} pl-9 sm:w-56`}
                />
              </div>
              <button type="button" onClick={openCreateUser} className={`${buttonClass} bg-cyan-600 text-white hover:bg-cyan-500`}>
                <Plus className="h-4 w-4" /> Tambah pengguna
              </button>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full min-w-[850px] text-left text-sm">
              <thead className="bg-slate-950/60 text-xs uppercase text-slate-400">
                <tr>
                  <th className="px-4 py-3">Pengguna</th>
                  <th className="px-4 py-3">Email</th>
                  <th className="px-4 py-3">Role</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Login terakhir</th>
                  <th className="px-4 py-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/80">
                {visibleUsers.map((user) => (
                  <tr key={user.id} className="hover:bg-slate-800/30">
                    <td className="px-4 py-3">
                      <div className="font-medium text-slate-100">{user.full_name}</div>
                      <div className="mt-0.5 text-xs text-slate-400">@{user.username}</div>
                    </td>
                    <td className="px-4 py-3 text-slate-300">{user.email}</td>
                    <td className="px-4 py-3 text-slate-300">{user.role_name}</td>
                    <td className="px-4 py-3">
                      <span className={`rounded-full border px-2 py-1 text-xs ${
                        Number(user.is_active) === 1
                          ? 'border-emerald-800 bg-emerald-950/50 text-emerald-300'
                          : 'border-slate-700 bg-slate-800 text-slate-400'
                      }`}>
                        {Number(user.is_active) === 1 ? 'Aktif' : 'Nonaktif'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-xs text-slate-400">
                      {user.last_login ? new Date(user.last_login).toLocaleString('id-ID') : 'Belum pernah'}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex justify-end gap-2">
                        <button type="button" title="Edit pengguna" onClick={() => openEditUser(user)}
                          className="rounded-lg border border-slate-700 p-2 text-slate-300 hover:border-cyan-700 hover:text-cyan-300">
                          <Pencil className="h-4 w-4" />
                        </button>
                        {Number(user.is_active) === 1 && (
                          <button type="button" title="Nonaktifkan pengguna" onClick={() => deactivateUser(user)}
                            className="rounded-lg border border-slate-700 p-2 text-slate-300 hover:border-rose-700 hover:text-rose-300">
                            <Power className="h-4 w-4" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
                {visibleUsers.length === 0 && (
                  <tr><td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                    {users.length ? 'Tidak ada pengguna yang cocok dengan pencarian.' : 'Belum ada akun pengguna.'}
                  </td></tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
      ) : tab === 'roles' && canRoles ? (
        <section className="overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50">
          <div className="flex flex-col gap-3 border-b border-slate-800 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="font-semibold text-white">Role dan hak akses</h2>
              <p className="mt-1 text-xs text-slate-400">{roles.length} role terdaftar</p>
            </div>
            <button type="button" onClick={openCreateRole} className={`${buttonClass} bg-cyan-600 text-white hover:bg-cyan-500`}>
              <Plus className="h-4 w-4" /> Tambah role
            </button>
          </div>
          <div className="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
            {roles.map((role) => {
              const builtIn = role.id <= 5;
              return (
                <article key={role.id} className="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                      <div className="rounded-lg border border-cyan-900/70 bg-cyan-950/50 p-2 text-cyan-300">
                        <ShieldCheck className="h-5 w-5" />
                      </div>
                      <div className="min-w-0">
                        <h3 className="break-words font-semibold text-white">{role.name}</h3>
                        <p className="mt-1 text-xs text-slate-400">{role.description || 'Tidak ada deskripsi.'}</p>
                      </div>
                    </div>
                    {builtIn && <span title="Role bawaan dilindungi" className="text-slate-500"><Lock className="h-4 w-4" /></span>}
                  </div>
                  <div className="mt-4 flex flex-wrap gap-2 text-xs">
                    <span className="rounded-md border border-slate-700 px-2 py-1 text-slate-300">
                      {role.user_count ?? 0} pengguna
                    </span>
                    <span className="rounded-md border border-slate-700 px-2 py-1 text-slate-300">
                      {role.permissions?.length ?? 0} permission
                    </span>
                  </div>
                  <div className="mt-4 flex gap-2">
                    <button type="button" onClick={() => openEditRole(role)}
                      className={`${buttonClass} flex-1 border border-slate-700 text-slate-300 hover:border-cyan-700 hover:text-cyan-300`}>
                      <Pencil className="h-4 w-4" /> {role.id === 1 ? 'Lihat' : 'Edit'}
                    </button>
                    {!builtIn && (
                      <button type="button" onClick={() => deleteRole(role)}
                        className={`${buttonClass} border border-rose-900/70 text-rose-300 hover:bg-rose-950/40`}>
                        <X className="h-4 w-4" /> Hapus
                      </button>
                    )}
                  </div>
                </article>
              );
            })}
            {roles.length === 0 && (
              <p className="col-span-full py-8 text-center text-sm text-slate-500">Belum ada role yang dapat ditampilkan.</p>
            )}
          </div>
        </section>
      ) : null}

      {userEditor && canUsers && (
        <div className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-black/70 p-4 pt-10 backdrop-blur-sm">
          <form onSubmit={saveUser} className="w-full max-w-2xl rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-800 px-5 py-4">
              <div>
                <h2 className="font-semibold text-white">{editingUser ? 'Edit pengguna' : 'Tambah pengguna'}</h2>
                <p className="mt-1 text-xs text-slate-400">Password disimpan sebagai hash dan tidak ditampilkan kembali.</p>
              </div>
              <button type="button" onClick={() => setUserEditor(false)} className="rounded-lg p-2 text-slate-400 hover:bg-slate-800 hover:text-white">
                <X className="h-5 w-5" />
              </button>
            </div>
            <div className="grid gap-4 p-5 sm:grid-cols-2">
              <label><span className={labelClass}>Nama lengkap *</span>
                <input required maxLength={100} value={userForm.full_name} onChange={(e) => setUserForm({ ...userForm, full_name: e.target.value })} className={inputClass} />
              </label>
              <label><span className={labelClass}>Username *</span>
                <input required maxLength={60} pattern="[A-Za-z0-9._-]+" value={userForm.username} onChange={(e) => setUserForm({ ...userForm, username: e.target.value })} className={inputClass} />
              </label>
              <label><span className={labelClass}>Email *</span>
                <input required type="email" maxLength={120} value={userForm.email} onChange={(e) => setUserForm({ ...userForm, email: e.target.value })} className={inputClass} />
              </label>
              <label><span className={labelClass}>Role *</span>
                <select required value={userForm.role_id} onChange={(e) => setUserForm({ ...userForm, role_id: Number(e.target.value) })} className={inputClass}>
                  <option value={0} disabled>Pilih role</option>
                  {roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
                </select>
              </label>
              <label className="sm:col-span-2"><span className={labelClass}>Password {editingUser ? '(kosongkan jika tidak diubah)' : '*'}</span>
                <div className="relative">
                  <KeyRound className="absolute left-3 top-2.5 h-4 w-4 text-slate-500" />
                  <input type="password" autoComplete="new-password" minLength={12} required={!editingUser}
                    value={userForm.password} onChange={(e) => setUserForm({ ...userForm, password: e.target.value })}
                    placeholder={editingUser ? 'Minimal 12 karakter jika diganti' : 'Minimal 12 karakter'}
                    className={`${inputClass} pl-9`} />
                </div>
              </label>
              <label className="sm:col-span-2"><span className={labelClass}>Status akun</span>
                <select value={userForm.is_active} onChange={(e) => setUserForm({ ...userForm, is_active: Number(e.target.value) })} className={inputClass}>
                  <option value={1}>Aktif</option>
                  <option value={0}>Nonaktif</option>
                </select>
              </label>
              <p className="sm:col-span-2 text-xs text-slate-500">Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.</p>
            </div>
            <div className="flex justify-end gap-2 border-t border-slate-800 px-5 py-4">
              <button type="button" onClick={() => setUserEditor(false)} className={`${buttonClass} border border-slate-700 text-slate-300`}>Batal</button>
              <button type="submit" disabled={saving || !roles.length} className={`${buttonClass} bg-cyan-600 text-white hover:bg-cyan-500`}>
                <Save className="h-4 w-4" /> {saving ? 'Menyimpan...' : 'Simpan pengguna'}
              </button>
            </div>
          </form>
        </div>
      )}

      {roleEditor && canRoles && (
        <div className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-black/70 p-4 pt-10 backdrop-blur-sm">
          <form onSubmit={saveRole} className="w-full max-w-3xl rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-800 px-5 py-4">
              <div>
                <h2 className="font-semibold text-white">
                  {editingRole ? (editingRole.id === 1 ? 'Detail Superadmin' : 'Edit role') : 'Tambah role'}
                </h2>
                <p className="mt-1 text-xs text-slate-400">Atur izin fitur yang dapat diakses role ini.</p>
              </div>
              <button type="button" onClick={() => setRoleEditor(false)} className="rounded-lg p-2 text-slate-400 hover:bg-slate-800 hover:text-white">
                <X className="h-5 w-5" />
              </button>
            </div>
            <div className="space-y-5 p-5">
              <label className="block"><span className={labelClass}>Nama role *</span>
                <input required maxLength={50} readOnly={editingRole?.id === 1}
                  value={roleForm.name} onChange={(e) => setRoleForm({ ...roleForm, name: e.target.value })}
                  className={`${inputClass} read-only:opacity-70`} />
              </label>
              <label className="block"><span className={labelClass}>Deskripsi</span>
                <textarea rows={2} readOnly={editingRole?.id === 1}
                  value={roleForm.description} onChange={(e) => setRoleForm({ ...roleForm, description: e.target.value })}
                  className={inputClass} />
              </label>
              <div>
                <div className="mb-3 flex items-center justify-between">
                  <div>
                    <h3 className="text-sm font-semibold text-white">Permission</h3>
                    <p className="mt-1 text-xs text-slate-400">{roleForm.permissions.length} permission dipilih</p>
                  </div>
                  {editingRole?.id === 1 && <span className="text-xs text-amber-300">Role Superadmin dilindungi</span>}
                </div>
                <div className="grid gap-2 sm:grid-cols-2">
                  {permissionList.map((permission) => (
                    <label key={permission.id} className={`flex items-start gap-3 rounded-lg border border-slate-800 bg-slate-950/50 p-3 ${editingRole?.id === 1 ? 'opacity-75' : 'cursor-pointer hover:border-slate-600'}`}>
                      <input type="checkbox" disabled={editingRole?.id === 1}
                        checked={roleForm.permissions.includes(permission.id)}
                        onChange={(e) => togglePermission(permission.id, e.target.checked)}
                        className="mt-0.5 accent-cyan-500" />
                      <span className="min-w-0">
                        <span className="block break-all text-xs font-medium text-slate-200">{permission.code}</span>
                        <span className="mt-1 block text-xs text-slate-500">{permission.description || 'Tidak ada deskripsi'}</span>
                      </span>
                    </label>
                  ))}
                </div>
              </div>
            </div>
            <div className="flex justify-end gap-2 border-t border-slate-800 px-5 py-4">
              <button type="button" onClick={() => setRoleEditor(false)} className={`${buttonClass} border border-slate-700 text-slate-300`}>Tutup</button>
              {editingRole?.id !== 1 && (
                <button type="submit" disabled={saving} className={`${buttonClass} bg-cyan-600 text-white hover:bg-cyan-500`}>
                  <Save className="h-4 w-4" /> {saving ? 'Menyimpan...' : 'Simpan role'}
                </button>
              )}
            </div>
          </form>
        </div>
      )}
    </div>
  );
};
