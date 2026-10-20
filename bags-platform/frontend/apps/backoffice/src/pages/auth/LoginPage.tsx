import React from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Button, Input } from '@alfarid/ui';
import { useAuthStore } from '../../stores/auth.store';
import { useNavigate } from 'react-router-dom';

const loginSchema = z.object({
  email: z.string().email('برجاء إدخال بريد إلكتروني صحيح'),
  password: z.string().min(6, 'كلمة المرور يجب ألا تقل عن 6 أحرف'),
});

type LoginFormData = z.infer<typeof loginSchema>;

export function LoginPage() {
  const navigate = useNavigate();
  const { login } = useAuthStore();
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormData>({
    resolver: zodResolver(loginSchema),
  });

  const onSubmit = async (data: LoginFormData) => {
    // Scaffold login logic (tools setup)
    // In actual flow, this calls API_ENDPOINTS.AUTH.LOGIN
    login('dummy-jwt-token-backoffice', {
      id: 1,
      name: 'أدمن الفريد',
      email: data.email,
      roles: ['super_admin'],
      permissions: ['*'],
    });
    navigate('/admin');
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-zinc-100 p-4" dir="rtl">
      <div className="w-full max-w-md bg-white rounded-2xl shadow-xl p-8 border border-zinc-200">
        <div className="text-center mb-8">
          <h1 className="text-2xl font-bold text-zinc-900">تسجيل الدخول للنظام</h1>
          <p className="text-sm text-zinc-500 mt-2">لوحة الإدارة وبوابة الموردين</p>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <Input
            label="البريد الإلكتروني"
            type="email"
            placeholder="admin@alfarid.com"
            error={errors.email?.message}
            {...register('email')}
          />
          <Input
            label="كلمة المرور"
            type="password"
            placeholder="••••••••"
            error={errors.password?.message}
            {...register('password')}
          />

          <Button type="submit" className="w-full mt-2" isLoading={isSubmitting}>
            دخول للنظام
          </Button>
        </form>
      </div>
    </div>
  );
}
