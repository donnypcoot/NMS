import React, { useState, useEffect } from 'react';
import {
  Router,
  ShieldCheck,
  CheckCircle2,
  XCircle,
  RefreshCw,
  Save,
  Eye,
  EyeOff,
  LockKeyhole,
  Settings2
} from 'lucide-react';
import { api } from '../services/api';
import { Device } from '../types/nms';
import { StatusBadge } from '../components/StatusBadge';
import { EmptyState } from '../components/EmptyState';

type TestResult = {
  success: boolean;
  status: string;
  host?: string;
  port?: number;
  username?: string;
  latency_ms: number | null;
  message: string;
};
export const MikrotikPage: React.FC = () => {
  const [routers, setRouters] = useState<Device[]>([]);
  const [loading, setLoading] = useState(true);

  const [selectedRouter, setSelectedRouter] = useState<Device | null>(null);

  const [mikrotikUsername, setMikrotikUsername] = useState('');
  const [mikrotikPassword, setMikrotikPassword] = useState('');
  const [mikrotikPort, setMikrotikPort] = useState('8728');
  const [mikrotikSsl, setMikrotikSsl] = useState(false);

  const [showPassword, setShowPassword] = useState(false);
  const [saving, setSaving] = useState(false);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);

  const [testResult, setTestResult] = useState<TestResult | null>(null);
  const [testing, setTesting] = useState(false);

  const fetchRouters = async () => {
    try {
      setLoading(true);

      const data = await api.getDevices();

      const mRouters = (data.devices || []).filter(
        (d) =>
          d.device_type === 'ROUTER' ||
          d.vendor?.toLowerCase().includes('mikrotik')
      );

      setRouters(mRouters);

      if (mRouters.length > 0 && !selectedRouter) {
        selectRouter(mRouters[0]);
      }
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchRouters();
  }, []);

  const selectRouter = (router: Device) => {
    setSelectedRouter(router);

    setMikrotikUsername(
      (router as any).mikrotik_username || ''
    );

    setMikrotikPort(
      String((router as any).mikrotik_api_port || 8728)
    );

    setMikrotikSsl(
      Boolean((router as any).mikrotik_use_ssl)
    );

    /*
     * Password sengaja tidak diambil dari API.
     * User harus mengisi password jika ingin menggantinya.
     */
    setMikrotikPassword('');

    setSaveMessage(null);
    setTestResult(null);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!selectedRouter) {
      return;
    }

    setSaving(true);
    setSaveMessage(null);

    try {
      const payload: any = {
        mikrotik_api_port: parseInt(mikrotikPort, 10) || 8728,
        mikrotik_username: mikrotikUsername.trim(),
        mikrotik_use_ssl: mikrotikSsl
      };

      /*
       * Password hanya dikirim jika user mengisinya.
       * Jika kosong, backend mempertahankan password lama.
       */
      if (mikrotikPassword !== '') {
        payload.mikrotik_password = mikrotikPassword;
      }

      const res = await api.updateDevice(selectedRouter.id, payload);

      setSelectedRouter(res.device);

      setMikrotikPassword('');

      setRouters((prev) =>
        prev.map((router) =>
          router.id === selectedRouter.id
            ? {
                ...router,
                ...res.device
              }
            : router
        )
      );

      setSaveMessage('Konfigurasi MikroTik berhasil disimpan.');
    } catch (err: any) {
      setSaveMessage(
        err.message || 'Gagal menyimpan konfigurasi MikroTik.'
      );
    } finally {
      setSaving(false);
    }
  };
const handleTestConnection = async () => {
  if (!selectedRouter) return;

  setTesting(true);
  setTestResult(null);

  try {
    const res = await api.testMikrotik(selectedRouter.id);

    setTestResult(res);
  } catch (err: any) {
    setTestResult({
      success: false,
      status: 'ERROR',
      latency_ms: null,
      message:
        err.message ||
        'Gagal menguji koneksi MikroTik.'
    });
  } finally {
    setTesting(false);
  }
};
  return (
    <div className="space-y-6">

      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-800">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-white tracking-tight">
            Integrasi MikroTik RouterOS
          </h1>

          <p className="text-xs sm:text-sm text-slate-400 mt-1">
            Konfigurasi kredensial RouterOS API untuk fungsi isolir dan buka isolir pelanggan.
          </p>
        </div>
      </div>

      {/* Configuration */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 sm:p-6 shadow-xl">

        <div className="flex items-center gap-2 mb-5">
          <Settings2 className="w-5 h-5 text-cyan-400" />
          <h2 className="text-base font-semibold text-white">
            Konfigurasi Router MikroTik
          </h2>
        </div>

        {loading ? (
          <div className="p-8 text-center text-slate-400 text-xs">
            <RefreshCw className="w-5 h-5 animate-spin mx-auto mb-2 text-cyan-500" />
            Memuat router...
          </div>
        ) : routers.length === 0 ? (
          <EmptyState
            title="Belum Ada Router MikroTik"
            description="Daftarkan router melalui menu Perangkat dengan tipe ROUTER."
            icon={<Router className="w-6 h-6 text-slate-400" />}
          />
        ) : (
          <form onSubmit={handleSave} className="space-y-5">

            {/* Router */}
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Router MikroTik
              </label>

              <select
                value={selectedRouter?.id || ''}
                onChange={(e) => {
                  const router = routers.find(
                    (r) => r.id === Number(e.target.value)
                  );

                  if (router) {
                    selectRouter(router);
                  }
                }}
                className="w-full px-3 py-2.5 text-sm bg-slate-950 border border-slate-800 rounded-lg text-slate-100 focus:outline-none focus:border-cyan-500"
              >
                {routers.map((router) => (
                  <option key={router.id} value={router.id}>
                    {router.name} — {router.ip_address}
                  </option>
                ))}
              </select>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">

              {/* Username */}
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  Username MikroTik
                </label>

                <input
                  type="text"
                  value={mikrotikUsername}
                  onChange={(e) =>
                    setMikrotikUsername(e.target.value)
                  }
                  placeholder="contoh: nms-api"
                  autoComplete="off"
                  className="w-full px-3 py-2.5 text-sm bg-slate-950 border border-slate-800 rounded-lg text-slate-100 font-mono focus:outline-none focus:border-cyan-500"
                />
              </div>

              {/* Password */}
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  Password MikroTik
                </label>

                <div className="relative">
                  <LockKeyhole className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />

                  <input
                    type={showPassword ? 'text' : 'password'}
                    value={mikrotikPassword}
                    onChange={(e) =>
                      setMikrotikPassword(e.target.value)
                    }
                    placeholder="Kosongkan jika tidak ingin mengubah"
                    autoComplete="new-password"
                    className="w-full pl-9 pr-10 py-2.5 text-sm bg-slate-950 border border-slate-800 rounded-lg text-slate-100 font-mono focus:outline-none focus:border-cyan-500"
                  />

                  <button
                    type="button"
                    onClick={() =>
                      setShowPassword((v) => !v)
                    }
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300"
                  >
                    {showPassword ? (
                      <EyeOff className="w-4 h-4" />
                    ) : (
                      <Eye className="w-4 h-4" />
                    )}
                  </button>
                </div>

                <p className="text-[10px] text-slate-500 mt-1">
                  Password tersimpan terenkripsi di database.
                </p>
              </div>

              {/* Port */}
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  API Port
                </label>

                <input
                  type="number"
                  min="1"
                  max="65535"
                  value={mikrotikPort}
                  onChange={(e) =>
                    setMikrotikPort(e.target.value)
                  }
                  className="w-full px-3 py-2.5 text-sm bg-slate-950 border border-slate-800 rounded-lg text-slate-100 font-mono focus:outline-none focus:border-cyan-500"
                />

                <p className="text-[10px] text-slate-500 mt-1">
                  Default API: 8728, API-SSL: 8729.
                </p>
              </div>

              {/* SSL */}
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  Mode API
                </label>

                <label className="flex items-center gap-3 h-[42px] px-3 bg-slate-950 border border-slate-800 rounded-lg cursor-pointer">
                  <input
                    type="checkbox"
                    checked={mikrotikSsl}
                    onChange={(e) =>
                      setMikrotikSsl(e.target.checked)
                    }
                    className="w-4 h-4"
                  />

                  <span className="text-sm text-slate-300">
                    Gunakan API-SSL
                  </span>
                </label>
              </div>

            </div>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-3 border-t border-slate-800">

              <div className="text-[11px] text-slate-400">
                {selectedRouter && (
                  <>
                    Router:{' '}
                    <span className="font-mono text-cyan-400">
                      {selectedRouter.ip_address}
                    </span>
                  </>
                )}
              </div>

              <button
                type="submit"
                disabled={saving || !selectedRouter}
                className="px-4 py-2.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-500 rounded-lg shadow-sm transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
              >
                {saving ? (
                  <RefreshCw className="w-4 h-4 animate-spin" />
                ) : (
                  <Save className="w-4 h-4" />
                )}

                {saving
                  ? 'Menyimpan...'
                  : 'Simpan Konfigurasi'}
              </button>

            </div>

            {saveMessage && (
              <div className="p-3 rounded-lg bg-slate-950 border border-slate-800 text-xs text-slate-300">
                {saveMessage}
              </div>
            )}

          </form>
        )}
      </div>

      {/* Test */}
      {selectedRouter && (
        <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 sm:p-6 shadow-xl">

          <div className="flex items-center gap-2 mb-4">
            <Router className="w-5 h-5 text-blue-400" />

            <div>
              <h2 className="text-base font-semibold text-white">
                Uji Koneksi MikroTik
              </h2>

             <p className="text-[11px] text-slate-500">
  Pengujian mencakup koneksi dan autentikasi RouterOS API.
</p>
            </div>
          </div>

          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

            <div className="text-xs text-slate-400">
              <div>
                Router:{' '}
                <span className="text-cyan-400 font-mono">
                  {selectedRouter.ip_address}
                </span>
              </div>

              <div className="mt-1">
                Port:{' '}
                <span className="text-slate-300 font-mono">
                  {mikrotikPort}
                </span>
              </div>
            </div>

            <button
              type="button"
              onClick={handleTestConnection}
              disabled={testing}
              className="px-4 py-2 text-xs font-medium text-white bg-cyan-600 hover:bg-cyan-500 rounded-lg disabled:opacity-50 flex items-center justify-center gap-2"
            >
              {testing && (
                <RefreshCw className="w-3.5 h-3.5 animate-spin" />
              )}

              {testing
                ? 'Menguji...'
                : 'Tes Koneksi & Auth'}
            </button>

          </div>

          {testResult && (
            <div
              className={`mt-5 p-4 rounded-xl border text-xs ${
                testResult.success
                  ? 'bg-emerald-950/40 border-emerald-800/60 text-emerald-300'
                  : 'bg-rose-950/40 border-rose-800/60 text-rose-300'
              }`}
            >
              <div className="flex items-center gap-2 font-semibold">
                {testResult.success ? (
                  <CheckCircle2 className="w-4 h-4" />
                ) : (
                  <XCircle className="w-4 h-4" />
                )}

                <span>
                  {testResult.status}
                </span>
              </div>

              <p className="mt-1 text-slate-300">
                {testResult.message}
              </p>
{testResult.success && (
  <div className="mt-2 space-y-1 font-mono text-[11px] text-slate-400">
    {testResult.host && (
      <div>
        Host: <span className="text-slate-300">{testResult.host}</span>
      </div>
    )}

    {testResult.port && (
      <div>
        Port: <span className="text-slate-300">{testResult.port}</span>
      </div>
    )}

    {testResult.username && (
      <div>
        Username: <span className="text-slate-300">{testResult.username}</span>
      </div>
    )}
  </div>
)}
              {testResult.latency_ms !== null && (
                <div className="mt-2 font-mono text-[11px]">
                  Latency: {testResult.latency_ms} ms
                </div>
              )}
            </div>
          )}

        </div>
      )}

      {/* Registered Routers */}
      <div className="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">

        <div className="p-4 sm:p-5 border-b border-slate-800 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Router className="w-5 h-5 text-cyan-400" />
            <h2 className="text-base font-semibold text-white">
              Daftar Router MikroTik
            </h2>
          </div>

          <span className="text-xs text-slate-400">
            {routers.length} router
          </span>
        </div>

        {routers.length === 0 ? (
          <div className="p-8">
            <EmptyState
              title="Belum Ada Router"
              description="Daftarkan router melalui menu Perangkat."
              icon={<Router className="w-6 h-6 text-slate-400" />}
            />
          </div>
        ) : (
          <div className="overflow-x-auto">

            <table className="w-full text-left text-xs">

              <thead className="bg-slate-950/70 text-slate-400 font-semibold border-b border-slate-800">
                <tr>
                  <th className="py-3.5 px-4">Nama Router</th>
                  <th className="py-3.5 px-4">IP Address</th>
                  <th className="py-3.5 px-4">Username</th>
                  <th className="py-3.5 px-4">API</th>
                  <th className="py-3.5 px-4">Status Ping</th>
                  <th className="py-3.5 px-4">Konfigurasi</th>
                  <th className="py-3.5 px-4">Aksi</th>
                </tr>
              </thead>

              <tbody className="divide-y divide-slate-800/60">

                {routers.map((router) => {
                  const configured = Boolean(
                    (router as any).mikrotik_configured
                  );

                  return (
                    <tr
                      key={router.id}
                      className="hover:bg-slate-800/40 transition-colors"
                    >

                      <td className="py-3 px-4 font-semibold text-slate-200">
                        {router.name}
                      </td>

                      <td className="py-3 px-4 font-mono text-cyan-400">
                        {router.ip_address}
                      </td>

                      <td className="py-3 px-4 text-slate-300">
                        {(router as any).mikrotik_username || '—'}
                      </td>

                      <td className="py-3 px-4 font-mono text-slate-400">
                        {(router as any).mikrotik_api_port || 8728}
                      </td>

                      <td className="py-3 px-4">
                        <StatusBadge status={router.ping_status} />
                      </td>

                      <td className="py-3 px-4">
                        {configured ? (
                          <span className="inline-flex items-center gap-1.5 text-emerald-400">
                            <CheckCircle2 className="w-3.5 h-3.5" />
                            Configured
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1.5 text-amber-400">
                            Belum dikonfigurasi
                          </span>
                        )}
                      </td>

                      <td className="py-3 px-4">
                        <button
                          type="button"
                          onClick={() => selectRouter(router)}
                          className="px-2.5 py-1 text-[11px] font-medium text-cyan-300 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-md transition-colors"
                        >
                          Konfigurasi
                        </button>
                      </td>

                    </tr>
                  );
                })}

              </tbody>

            </table>

          </div>
        )}

      </div>

    </div>
  );
};
