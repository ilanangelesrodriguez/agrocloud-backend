<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return ['id_empresa' => Empresa::factory(), 'id_rol' => Rol::factory(), 'nombre' => fake()->firstName(), 'apellido' => fake()->lastName(), 'correo' => fake()->unique()->safeEmail(), 'password_hash' => 'StrongFactoryPassword123', 'estado' => true, 'email_verified_at' => now()];
    }
}
